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
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

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
        protected CommandResultPoolInterface $commandResultPool,
        protected InputPoolServiceInterface $inputPoolService,
        protected OptionsServiceInterface $optionsService,
        protected Message $messageModel,
        protected Cache $cache,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function think(
        AiPreset $currentPreset,
        ?AiPreset $mainPreset = null,
        ?string $handoffMessage = null
    ): AiAgentResponseInterface {
        try {

            // Get and setup preset
            $presetId = $currentPreset->getId();
            $this->setupPresetEnvironment($currentPreset);

            // 1. Build context
            $context = $this->buildContext($currentPreset, $mainPreset, $handoffMessage);

            // 2. Setup and generate response
            $response = $this->generateResponse($context, $currentPreset);

            // 3. Handle response
            $result = $this->agentActionsHandler->handleResponse($response, $currentPreset, $mainPreset);

            return $result;
        } catch (\Exception $e) {
            return $this->agentActionsHandler->handleError($e, $presetId);
        }
    }

    /**
     * Build context based on chat status
     *
     * @param AiPreset $preset Current preset
     * @return array
     */
    protected function buildContext(AiPreset $preset, ?AiPreset $mainPreset = null, ?string $handoffMessage = null): array
    {

        if ($handoffMessage && $mainPreset) {
            if ($preset->getInputMode() === 'pool') {
                $this->inputPoolService->add($preset->getId(), $mainPreset->getAvailableName(), $handoffMessage);
                $messageText = $this->inputPoolService->getAllAsJSON($preset->getId());
            } else {
                $messageFromUserLabel = $this->optionsService->get('model_message_from_user', 'message_from_user');
                $messageText = "$messageFromUserLabel {$preset->getAvailableName()}:\n$handoffMessage";
                $messageText = $handoffMessage;
            }

            $this->messageModel->create([
                'role' => 'user',
                'content' => $messageText,
                'from_user_id' => null,
                'preset_id' => $preset->getId()
            ]);

        }

        $mode = $this->chatStatusService->getChatStatus() ? self::MODE_CYCLE : self::MODE_SINGLE;
        $contextBuilder = $this->contextBuilderFactory->getContextBuilder($mode);

        $context = $contextBuilder->build($preset);


        return $context;
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

        // Execute pre-run commands and expose results as [[pre_command_results]].
        // Runs after shortcodes are set up so the preset environment is fully ready.
        $this->commandPreRunner->run($preset, $preset);
    }

}
