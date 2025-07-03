<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

class Agent implements AgentInterface
{
    private const MODE_CYCLE = "cycle";
    private const MODE_SINGLE = 'single';

    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected AgentActionsInterface $agentActions,
        protected MemoryServiceInterface $memoryService,
        protected OptionsServiceInterface $optionsService,
        protected ShortcodeManagerServiceInterface $shortcodeManagerService,
        protected PluginRegistryInterface $pluginRegistry,
        protected ContextBuilderFactoryInterface $contextBuilderFactory,
        protected ChatStatusServiceInterface $chatStatusService,
        protected PluginMetadataServiceInterface $pluginMetadataService,
        protected Message $messageModel,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function think(): Message
    {
        try {

            // Get and setup preset
            $defaultPreset = $this->presetRegistry->getDefaultPreset();
            $presetId = $defaultPreset->getId();
            $this->setupPresetEnvironment($defaultPreset);

            // 1. Build context
            $context = $this->buildContext($defaultPreset);

            // 2. Setup and generate response
            $response = $this->generateResponse($context, $defaultPreset);

            // 3. Handle response
            return $this->handleResponse($response, $presetId);

        } catch (\Exception $e) {
            return $this->handleError($e, $presetId);
        }
    }

    /**
     * Build context based on chat status
     *
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
     * @return mixed
     */
    protected function generateResponse(array $context, AiPreset $preset)
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
     * Setup preset environment (plugins, shortcodes)
     *
     * @param AiPreset $preset
     * @return void
     */
    protected function setupPresetEnvironment(AiPreset $preset): void
    {
        $this->pluginRegistry->setCurrentPreset($preset);
        $this->shortcodeManagerService->setDefaultShortcodes();
    }

    /**
     * Handle successful or error response
     *
     * @param mixed $response
     * @param int $presetId
     * @return Message
     */
    protected function handleResponse($response, int $presetId): Message
    {
        if ($response->isError()) {
            return $this->createSystemMessage(
                $response->getResponse(),
                $presetId,
                $response->getMetadata()
            );
        }

        return $this->processSuccessfulResponse($response, $presetId);
    }

    /**
     * Process successful AI response through actions
     *
     * @param mixed $response
     * @param int $presetId
     * @return Message
     */
    protected function processSuccessfulResponse($response, int $presetId): Message
    {
        $output = $response->getResponse();
        $actionsResult = $this->agentActions->runActions($output);

        if ($actionsResult->getSystemMessage()) {
            $this->createSystemMessage(
                $actionsResult->getSystemMessage(),
                $presetId
            );
        }

        return $this->messageModel->create([
            'role' => $actionsResult->getRole(),
            'content' => $actionsResult->getResult(),
            'from_user_id' => null,
            'preset_id' => $presetId,
            'is_visible_to_user' => $actionsResult->isVisibleForUser(),
            'metadata' => $response->getMetadata()
        ]);
    }

    /**
     * Create system message
     *
     * @param string $content
     * @param int $presetId
     * @param array $metadata
     * @return Message
     */
    protected function createSystemMessage(string $content, int $presetId, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role' => 'system',
            'content' => $content,
            'from_user_id' => null,
            'preset_id' => $presetId,
            'is_visible_to_user' => true,
            'metadata' => $metadata
        ]);
    }

    /**
     * Handle errors during thinking process
     *
     * @param \Exception $e
     * @param int $presetId
     * @return Message
     */
    protected function handleError(\Exception $e, int $presetId): Message
    {
        $this->logger->error("Agent: Error in think method", [
            'error_message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->createSystemMessage(
            "Error in thinking process: " . $e->getMessage(),
            $presetId
        );
    }
}
