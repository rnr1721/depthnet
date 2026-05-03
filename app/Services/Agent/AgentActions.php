<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AiActionsResponseInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandLinterInterface;
use App\Contracts\Agent\CommandPreProcessorInterface;
use App\Contracts\Agent\ToolCallParserInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;
use Psr\Log\LoggerInterface;

/**
 * Parses and executes commands from an AI model response.
 *
 * Supports two command modes, selected via preset's agent_result_mode:
 *
 * **tags (default — inline/separate/internal)**
 *   The classic pipeline: PreProcessor → CommandParserSmart → CommandExecutor → Linter.
 *   The model embeds commands as special tags in its text output:
 *     [memory show][/memory]
 *     [run python]print("hello")[/run]
 *
 * **tool_calls (agent_result_mode = 'tool_calls')**
 *   The native pipeline: ToolCallParser → CommandExecutor (no PreProcessor, no Linter).
 *   The model uses the provider's structured tool-calling mechanism instead of tags.
 *   The engine serializes tool_calls as JSON; ToolCallParser maps them to the same
 *   ParsedCommand[] format. CommandExecutor is completely unchanged.
 *
 * Both paths share the same CommandExecutor, result formatting, and
 * AgentActionsHandler persistence/routing — only the parsing front-end differs.
 */
class AgentActions implements AgentActionsInterface
{
    private const ROLE_USER     = 'user';
    private const ROLE_THINKING = 'thinking';
    private const ROLE_COMMAND  = 'command';

    public function __construct(
        protected CommandPreProcessorInterface $commandPreProcessor,
        protected CommandParserInterface $commandParser,
        protected CommandExecutorInterface $commandExecutor,
        protected CommandLinterInterface $commandLinter,
        protected ChatStatusServiceInterface $chatStatusService,
        protected OptionsServiceInterface $optionsService,
        protected LoggerInterface $logger,
        protected ?ToolCallParserInterface $toolCallParser = null,
    ) {
    }

    /**
     * Parse and execute commands from an AI model response string.
     *
     * Dispatches to the appropriate parsing pipeline based on
     * preset->getAgentResultMode(). 'tool_calls' uses ToolCallParser,
     * all other modes use the classic tag pipeline.
     *
     * @param  string                   $responseString Raw AI model output
     * @param  AiPreset                 $preset
     * @param  AiPreset|null            $mainPreset     Caller preset (legacy enricher path)
     * @param  bool                     $isUser
     * @return AiActionsResponseInterface
     */
    public function runActions(
        string $responseString,
        AiPreset $preset,
        ?AiPreset $mainPreset = null,
        bool $isUser = false
    ): AiActionsResponseInterface {
        $responseString = $this->cleanupResponse($responseString, $preset);

        if ($preset->getAgentResultMode() === 'tool_calls') {
            return $this->runToolCallActions($responseString, $preset, $mainPreset, $isUser);
        }

        return $this->runTagActions($responseString, $preset, $mainPreset, $isUser);
    }

    // ── Tool-calls pipeline ──────────────────────────────────────────────────

    /**
     * Execute the tool_calls parsing pipeline.
     *
     * Steps:
     *   1. Parse tool_calls JSON → ParsedCommand[] via ToolCallParser
     *   2. Execute commands via CommandExecutor
     *   3. Return ActionsResponseDTO (no linting — structured JSON has no syntax errors)
     *
     * @param  string        $responseString JSON string with tool_calls
     * @param  AiPreset      $preset
     * @param  AiPreset|null $mainPreset
     * @param  bool          $isUser
     * @return AiActionsResponseInterface
     */
    protected function runToolCallActions(
        string $responseString,
        AiPreset $preset,
        ?AiPreset $mainPreset,
        bool $isUser
    ): AiActionsResponseInterface {
        if (!$this->toolCallParser) {
            $this->logger->error(
                'AgentActions: tool_calls mode is enabled but ToolCallParser is not injected. '
                . 'Check your service provider bindings.'
            );
            return $this->emptyResponse($isUser);
        }

        $commands = $this->toolCallParser->parse($responseString);

        return $this->buildActionsResponse($commands, $preset, $mainPreset, $isUser);
    }

    // ── Tag pipeline ─────────────────────────────────────────────────────────

    /**
     * Execute the tag-based parsing pipeline.
     *
     * Steps:
     *   1. Append auto-handoff tag if preset has preset_code_next configured
     *   2. PreProcess: auto-close self-closing tags, flatten nested commands
     *   3. Parse tag commands → ParsedCommand[] via CommandParserSmart
     *   4. Execute commands via CommandExecutor
     *   5. Lint original response for syntax errors and append to result
     *
     * @param  string        $responseString
     * @param  AiPreset      $preset
     * @param  AiPreset|null $mainPreset
     * @param  bool          $isUser
     * @return AiActionsResponseInterface
     */
    protected function runTagActions(
        string $responseString,
        AiPreset $preset,
        ?AiPreset $mainPreset,
        bool $isUser
    ): AiActionsResponseInterface {
        if (!$isUser) {
            $responseString = $this->addAutoHandoffIfNeeded($responseString, $preset);
        }

        $preprocessedOutput = $this->commandPreProcessor->preProcess($responseString);
        $commands           = $this->commandParser->parse($preprocessedOutput);
        $actionsResponse    = $this->buildActionsResponse($commands, $preset, $mainPreset, $isUser);

        // Lint runs on the original string (not preprocessed) for accurate error reporting.
        // Errors are appended to the result so the model corrects them next cycle.
        $lintOutput = $this->lintResults($responseString);
        if ($lintOutput) {
            return $actionsResponse->withAppendedResult($lintOutput);
        }

        return $actionsResponse;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Build an ActionsResponseDTO from a parsed command list.
     * Shared by both tag and tool_calls pipelines.
     *
     * @param  array         $commands
     * @param  AiPreset      $preset
     * @param  AiPreset|null $mainPreset
     * @param  bool          $isUser
     * @return AiActionsResponseInterface
     */
    protected function buildActionsResponse(
        array $commands,
        AiPreset $preset,
        ?AiPreset $mainPreset,
        bool $isUser
    ): AiActionsResponseInterface {
        $output        = '';
        $systemMessage = null;
        $visibleToUser = false;
        $role          = $isUser ? self::ROLE_USER : self::ROLE_THINKING;

        $executionResult = $this->executeCommands($commands, $preset, $mainPreset);

        if ($executionResult) {
            $role   = self::ROLE_COMMAND;
            $output = $executionResult->formattedMessage;

            if (isset($executionResult->pluginExecutionMeta['speak'])) {
                $systemMessage = $executionResult->pluginExecutionMeta['speak'];
                $visibleToUser = true;
            }
        }

        $handoff = $executionResult?->pluginExecutionMeta['handoff'] ?? null;
        $turn = (bool) ($executionResult?->pluginExecutionMeta['turn'] ?? false);

        return new ActionsResponseDTO(
            $output,
            $role,
            $role === self::ROLE_COMMAND,
            $visibleToUser,
            $systemMessage,
            $handoff,
            $executionResult?->results ?? [],
            $turn
        );
    }

    /**
     * Return an empty ActionsResponseDTO — used as safe fallback.
     *
     * @param  bool $isUser
     * @return AiActionsResponseInterface
     */
    protected function emptyResponse(bool $isUser): AiActionsResponseInterface
    {
        return new ActionsResponseDTO(
            '',
            $isUser ? self::ROLE_USER : self::ROLE_THINKING,
            false,
            false
        );
    }

    /**
     * Remove fake system command result blocks the model may have hallucinated.
     * Only applies in 'separate' result mode.
     *
     * @param  string   $response
     * @param  AiPreset $preset
     * @return string
     */
    private function cleanupResponse(string $response, AiPreset $preset): string
    {
        if ($preset->getAgentResultMode() === 'separate') {
            $response = preg_replace('/<system_output_results>.*?```\s*$/s', '', $response);
            $response = preg_replace('/```system_command_results.*?```/s', '', $response);
            $response = preg_replace('/🤖\s*Command\s*Results:\s*/i', '', $response);
        }

        return trim($response);
    }

    /**
     * Run the command linter and format errors for model feedback.
     * Only used in tag mode.
     *
     * @param  string      $responseString
     * @return string|null
     */
    private function lintResults(string $responseString): ?string
    {
        $lintResults = $this->commandLinter->lint($responseString);

        if (empty($lintResults)) {
            return null;
        }

        $output = "\n\nCOMMAND SYNTAX ERRORS:\n";
        foreach ($lintResults as $error) {
            $output .= $error . "\n";
        }

        return $output;
    }

    /**
     * Execute parsed commands through CommandExecutor.
     *
     * @param  array         $commands
     * @param  AiPreset      $preset
     * @param  AiPreset|null $mainPreset
     * @return CommandExecutionResult|null
     */
    private function executeCommands(
        array $commands,
        AiPreset $preset,
        ?AiPreset $mainPreset = null
    ): ?CommandExecutionResult {
        if (empty($commands)) {
            return null;
        }

        return $this->commandExecutor->executeCommands($commands, $preset, $mainPreset);
    }

    /**
     * Inject an auto-handoff tag if the preset has preset_code_next configured
     * and the response does not already contain an explicit handoff command.
     *
     * @param  string   $responseString
     * @param  AiPreset $currentPreset
     * @return string
     */
    private function addAutoHandoffIfNeeded(string $responseString, AiPreset $currentPreset): string
    {
        try {
            $nextPresetCode = $currentPreset->getPresetCodeNext();
            $defaultMessage = $currentPreset->getDefaultCallMessage();

            if (empty($nextPresetCode) || empty($defaultMessage)) {
                return $responseString;
            }

            if ($this->hasExplicitHandoff($responseString)) {
                return $responseString;
            }

            return $responseString . "\n[agent handoff]{$nextPresetCode}:{$defaultMessage}[/agent]";

        } catch (\Throwable $e) {
            return $responseString;
        }
    }

    /**
     * @param  string $responseString
     * @return bool
     */
    private function hasExplicitHandoff(string $responseString): bool
    {
        return preg_match('/\[agent\s+handoff\]/i', $responseString) === 1;
    }
}
