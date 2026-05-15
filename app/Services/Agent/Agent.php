<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandPreRunnerInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\ToolSchemaBuilderInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;

/**
 * Core agent that orchestrates a single thinking cycle.
 *
 * Responsibilities:
 *   1. Prepare the preset environment (apply plugins, register shortcodes, run pre-commands)
 *   2. Build the conversation context for the current cycle
 *   3. Generate an AI response via the preset's engine
 *   4. Delegate response handling (command execution, persistence, inter-agent routing)
 *      to AgentActionsHandler
 *
 * The agent is stateless between cycles — all state lives in the database,
 * cache, and plugin metadata stores. Each call to think() is independent.
 *
 * Tool_calls mode (agent_result_mode = 'tool_calls'):
 *   A single flag that controls the entire tool_calls pipeline:
 *     - tools array is built and attached to the model request
 *     - [[command_instructions]] shortcode is suppressed (empty string) at
 *       preset scope — the model learns about available tools through the
 *       tools array sent to the API, not through tag syntax in the prompt
 *     - ToolCallParser is used instead of CommandParserSmart
 *     - history is stored in assistant/tool turn format
 */
class Agent implements AgentInterface
{
    private const MODE_CYCLE  = 'cycle';
    private const MODE_SINGLE = 'single';

    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected CommandPreRunnerInterface $commandPreRunner,
        protected AgentActionsHandlerInterface $agentActionsHandler,
        protected MemoryServiceInterface $memoryService,
        protected ShortcodeManagerServiceInterface $shortcodeManagerService,
        protected PluginRegistryInterface $pluginRegistry,
        protected ContextBuilderFactoryInterface $contextBuilderFactory,
        protected ChatStatusServiceInterface $chatStatusService,
        protected PluginMetadataServiceInterface $pluginMetadataService,
        protected CommandResultPoolInterface $commandResultPool,
        protected ToolSchemaBuilderInterface $toolSchemaBuilder,
    ) {
    }

    /**
     * Execute a single thinking cycle for the given preset.
     *
     * @param  AiPreset              $currentPreset
     * @return AiAgentResponseInterface
     */
    public function think(AiPreset $currentPreset): AiAgentResponseInterface
    {
        $presetId = $currentPreset->getId();
        try {
            $this->setupPresetEnvironment($currentPreset);

            $context  = $this->buildContext($currentPreset);
            $response = $this->generateResponse($context, $currentPreset);
            $result   = $this->agentActionsHandler->handleResponse($response, $currentPreset);

            return $result;
        } catch (\Exception $e) {
            return $this->agentActionsHandler->handleError($e, $presetId);
        }
    }

    /**
     * Build the conversation context for the current thinking cycle.
     *
     * @param  AiPreset $preset
     * @return array
     */
    protected function buildContext(AiPreset $preset): array
    {
        $mode           = $this->chatStatusService->getChatStatus() ? self::MODE_CYCLE : self::MODE_SINGLE;
        $contextBuilder = $this->contextBuilderFactory->getContextBuilder($mode);

        return $contextBuilder->build($preset);
    }

    /**
     * Generate an AI response using the preset's configured engine.
     *
     * When agent_result_mode = 'tool_calls', ToolSchemaBuilder assembles
     * OpenAI-compatible tool schemas from all enabled plugins and attaches
     * them to the request via additionalParams['tools']. The engine forwards
     * them to the provider API.
     *
     * In all other modes no tools are attached — the model uses tag syntax
     * described in the system prompt via [[command_instructions]].
     *
     * @param  array    $context
     * @param  AiPreset $preset
     * @return AiModelResponseInterface
     */
    protected function generateResponse(array $context, AiPreset $preset): AiModelResponseInterface
    {
        $currentEngine    = $this->presetRegistry->createInstance($preset->getId());
        $additionalParams = [];

        if ($preset->getAgentResultMode() === 'tool_calls') {
            $additionalParams['tools'] = $this->toolSchemaBuilder->buildForPreset($preset);
        }

        return $currentEngine->generate(
            new ModelRequestDTO(
                $preset,
                $this->memoryService,
                $this->commandInstructionBuilder,
                $this->shortcodeManagerService,
                $this->pluginMetadataService,
                $context,
                $additionalParams
            )
        );
    }

    /**
     * Prepare the preset environment before the thinking cycle begins.
     *
     * Steps:
     *   1. Apply preset-specific plugin configuration via PluginRegistry
     *   2. Register default shortcodes (datetime, dopamine, etc.)
     *   3. In tool_calls mode: suppress [[command_instructions]] at preset scope.
     *      The global shortcode returns tag syntax documentation which is irrelevant
     *      when the model uses native tool_calls. The model learns about available
     *      tools through the tools array in the API request instead.
     *   4. In internal mode: register [[agent_command_results]] shortcode
     *   5. Execute pre-run commands and expose results via [[pre_command_results]]
     *
     * @param  AiPreset $preset
     * @return void
     */
    protected function setupPresetEnvironment(AiPreset $preset): void
    {
        $this->pluginRegistry->applyPreset($preset);
        $this->shortcodeManagerService->setDefaultShortcodes($preset);

        if ($preset->getAgentResultMode() === 'internal') {
            $this->shortcodeManagerService->registerShortcodeForPreset(
                $preset->getId(),
                'agent_command_results',
                '',
                fn () => $this->commandResultPool->getFormatted($preset)
            );
        }

        $memo = $this->pluginMetadataService->get($preset, 'memo', 'self_system_note', null);
        if ($memo && is_string($memo)) {
            // Consume immediately and deterministically — read once, delete once.
            // Putting remove() inside the resolver would tie deletion to placeholder
            // presence in the prompt, which is a user-editable concern. The note
            // should be consumed at the start of every cycle that finds it pending,
            // regardless of whether [[memo]] appears in the rendered prompt.
            $this->pluginMetadataService->remove($preset, 'memo', 'self_system_note');

            $this->shortcodeManagerService->registerShortcodeForPreset(
                $preset->getId(),
                'memo',
                '',
                fn () => $memo
            );
        }

        $this->commandPreRunner->run($preset, $preset);
    }
}
