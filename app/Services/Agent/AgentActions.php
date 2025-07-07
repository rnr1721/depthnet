<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AiActionsResponseInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandLinterInterface;
use App\Contracts\Agent\CommandPreProcessorInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;
use Psr\Log\LoggerInterface;

class AgentActions implements AgentActionsInterface
{
    private const ROLE_USER = 'user';
    private const ROLE_THINKING = 'thinking';
    private const ROLE_COMMAND = 'command';

    public function __construct(
        protected CommandPreProcessorInterface $commandPreProcessor,
        protected CommandParserInterface $commandParser,
        protected CommandExecutorInterface $commandExecutor,
        protected CommandLinterInterface $commandLinter,
        protected ChatStatusServiceInterface $chatStatusService,
        protected OptionsServiceInterface $optionsService,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function runActions(string $responseString, AiPreset $preset, bool $isUser = false): AiActionsResponseInterface
    {
        // Step 1: Check for auto-handoff before processing
        if (!$isUser) {
            $responseString = $this->addAutoHandoffIfNeeded($responseString, $preset);
        }

        // Step 2: Pre-process the response (auto-close tags, extract nested commands)
        $preprocessedOutput = $this->commandPreProcessor->preProcess($responseString);

        // Step 3: Parse commands from pre-processed output
        $commands = $this->commandParser->parse($preprocessedOutput);

        $output = '';

        $systemMessage = null;
        $visibleToUser = false;
        $role = $isUser ? self::ROLE_USER : self::ROLE_THINKING;

        // Step 4: Execute commands
        $executionResult = $this->executeCommands($commands);
        if ($executionResult) {
            $role = self::ROLE_COMMAND;
            $output .= $executionResult->formattedMessage;
            if (isset($executionResult->pluginExecutionMeta['speak'])) {
                $systemMessage = $executionResult->pluginExecutionMeta['speak'];
                $visibleToUser = true;
            }
        }

        // Step 5: Lint the ORIGINAL response (not pre-processed) for better error messages
        $lintResults = $this->lintResults($responseString);
        if ($lintResults) {
            $output .= $lintResults;
        }

        return new ActionsResponseDTO(
            $output,
            $role,
            $visibleToUser,
            $systemMessage,
            $executionResult->pluginExecutionMeta['handoff'] ?? null
        );
    }

    /**
     * Lint command syntax
     * Information about command syntax for model
     *
     * @param string $responseString Model response
     * @return string|null
     */
    private function lintResults(string $responseString): ?string
    {
        $lintResults = $this->commandLinter->lint($responseString);
        if (empty($lintResults)) {
            return null;
        }

        $lintResultsString = "\n\nCOMMAND SYNTAX ERRORS:\n";
        foreach ($lintResults as $error) {
            $lintResultsString .= $error . "\n";
        }
        return $lintResultsString;
    }

    /**
     * Execute commands if any are present
     *
     * @param array $commands
     * @return CommandExecutionResult|null
     */
    private function executeCommands(array $commands): ?CommandExecutionResult
    {
        if (empty($commands)) {
            return null;
        }

        return $this->commandExecutor->executeCommands($commands);
    }

    /**
     * Add automatic handoff command if preset has preset_code_next configured
     * and no explicit handoff command already exists in the response
     *
     * @param string $responseString
     * @param AiPreset $preset
     * @return string
     */
    private function addAutoHandoffIfNeeded(string $responseString, AiPreset $currentPreset): string
    {
        try {

            $nextPresetCode = $currentPreset->getPresetCodeNext();
            $defaultMessage = $currentPreset->getDefaultCallMessage();

            // If no auto-handoff configured, return original response
            if (empty($nextPresetCode) || empty($defaultMessage)) {
                return $responseString;
            }

            // Check if response already contains handoff command
            if ($this->hasExplicitHandoff($responseString)) {
                return $responseString;
            }

            // Add automatic handoff command
            $autoHandoffCommand = "\n[agent handoff]{$nextPresetCode}:{$defaultMessage}[/agent]";

            return $responseString . $autoHandoffCommand;
        } catch (\Throwable $e) {
            // Return original response on error
            return $responseString;
        }
    }

    /**
     * Check if response already contains explicit handoff command
     *
     * @param string $responseString
     * @return bool
     */
    private function hasExplicitHandoff(string $responseString): bool
    {
        // Look for [agent handoff] pattern (case insensitive)
        return preg_match('/\[agent\s+handoff\]/i', $responseString) === 1;
    }
}
