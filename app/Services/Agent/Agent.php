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
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;

class Agent implements AgentInterface
{
    private const MODE_CYCLE = "cycle";
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
        protected CommandResultPoolInterface $commandResultPool
    ) {
    }

    /**
     * @inheritDoc
     *
     * Handoff messages now arrive via AgentMessageService before think()
     * is called — they are already in the preset's input pool or message
     * history, so there is no need to inject them here.
     */
    public function think(
        AiPreset $currentPreset,
    ): AiAgentResponseInterface {
        try {
            $presetId = $currentPreset->getId();
            $this->setupPresetEnvironment($currentPreset);

            // 1. Build context (handoff messages already in pool/history)
            $context = $this->buildContext($currentPreset);

            // 2. Generate response
            $response = $this->generateResponse($context, $currentPreset);

            // 3. Handle response (inter-agent delivery happens inside if needed)
            $result = $this->agentActionsHandler->handleResponse($response, $currentPreset);

            return $result;
        } catch (\Exception $e) {
            return $this->agentActionsHandler->handleError($e, $presetId);
        }
    }

    /**
     * Build context based on chat status.
     *
     * @param AiPreset $preset Current preset
     * @return array
     */
    protected function buildContext(AiPreset $preset): array
    {
        $mode = $this->chatStatusService->getChatStatus() ? self::MODE_CYCLE : self::MODE_SINGLE;
        $contextBuilder = $this->contextBuilderFactory->getContextBuilder($mode);

        return $contextBuilder->build($preset);
    }

    /**
     * Generate AI response using current preset
     *
     * @param array $context
     * @param AiPreset $preset
     * @return AiModelResponseInterface
     */
    protected function generateResponse(array $context, AiPreset $preset): AiModelResponseInterface
    {
        $currentEngine = $this->presetRegistry->createInstance($preset->getId());

        return $currentEngine->generate(
            new ModelRequestDTO(
                $preset,
                $this->memoryService,
                $this->commandInstructionBuilder,
                $this->shortcodeManagerService,
                $this->pluginMetadataService,
                $context
            )
        );
    }

    /**
     * Setup preset environment (plugins, shortcodes, pre-run commands)
     *
     * @param AiPreset $preset
     * @return void
     */
    protected function setupPresetEnvironment(AiPreset $preset): void
    {
        $this->pluginRegistry->applyPreset($preset);
        $this->shortcodeManagerService->setDefaultShortcodes();

        if ($preset->getAgentResultMode() === 'internal') {
            $this->shortcodeManagerService->registerShortcodeForPreset(
                $preset->getId(),
                'agent_command_results',
                '',
                fn () => $this->commandResultPool->getFormatted($preset)
            );
        }

        $this->commandPreRunner->run($preset, $preset);
    }
}
