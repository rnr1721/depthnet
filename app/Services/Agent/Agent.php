<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
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
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\DTO\AgentResponseDTO;
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
            $context = $this->buildContext($mainPreset ?? $currentPreset, $handoffMessage);

            // 2. Setup and generate response
            $response = $this->generateResponse($context, $currentPreset, $handoffMessage);

            // 3. Handle response
            return $this->handleResponse($response, $mainPreset ?? $currentPreset);

        } catch (\Exception $e) {
            return $this->handleError($e, $presetId);
        }
    }

    /**
     * Build context based on chat status
     *
     * @param AiPreset $preset Current preset
     * @return array
     */
    protected function buildContext(AiPreset $preset, ?string $handoffMessage = null): array
    {
        $mode = $this->chatStatusService->getChatStatus() ? self::MODE_CYCLE : self::MODE_SINGLE;
        $contextBuilder = $this->contextBuilderFactory->getContextBuilder($mode);

        $context = $contextBuilder->build($preset);
        if ($handoffMessage) {
            $context[] = [
                'role' => 'user',
                'content' => "[Handoff Task: {$handoffMessage}]",
                'from_user_id' => null
            ];
        }

        return $context;
    }

    /**
     * Generate AI response using current preset
     *
     * @param array $context
     * @param AiPreset $preset
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
     * @param AiPreset $preset
     * @return AiAgentResponseInterface
     */
    protected function handleResponse(
        $response,
        AiPreset $preset
    ): AiAgentResponseInterface {
        if ($response->isError()) {
            $errorMessage = $this->createSystemMessage(
                $response->getResponse(),
                $preset->getId(),
                $response->getMetadata()
            );

            return new AgentResponseDTO(
                $errorMessage,
                new ActionsResponseDTO('', 'system', true),
                true,
                $response->getResponse()
            );
        }

        $result = $this->processSuccessfulResponse($response, $preset);

        return new AgentResponseDTO(
            $result['message'],
            $result['actionsResult'],
            false
        );
    }

    /**
     * Process successful AI response through actions
     *
     * @param mixed $response
     * @param AiPreset $preset
     * @return array
     */
    protected function processSuccessfulResponse($response, AiPreset $preset): array
    {
        $output = $response->getResponse();
        $actionsResult = $this->agentActions->runActions($output, $preset);

        $method = $preset->getAgentResultMode();

        $messageContent = $method === 'separate' ? $response->getResponse() : $response->getResponse() . "\n" . $actionsResult->getResult();

        $message = $this->messageModel->create([
            'role' => $actionsResult->getRole(),
            'content' => $messageContent,
            'from_user_id' => null,
            'preset_id' => $preset->getId(),
            'is_visible_to_user' => $actionsResult->isVisibleForUser(),
            'metadata' => $response->getMetadata()
        ]);

        if ($method === 'separate' && !empty(trim($actionsResult->getResult()))) {
            $this->messageModel->create([
                'role' => 'result',
                'content' => $actionsResult->getResult(),
                'from_user_id' => null,
                'preset_id' => $preset->getId(),
                'is_visible_to_user' => true,
            ]);
        }

        if ($actionsResult->getSystemMessage()) {
            $this->createSystemMessage(
                $actionsResult->getSystemMessage(),
                $preset->getId()
            );
        }

        return [
            'actionsResult' => $actionsResult,
            'message' => $message
        ];
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
     * @return AiAgentResponseInterface
     */
    protected function handleError(\Exception $e, int $presetId): AiAgentResponseInterface
    {
        $this->logger->error("Agent: Error in think method", [
            'error_message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        $errorMessage = $this->createSystemMessage(
            "Error in thinking process: " . $e->getMessage(),
            $presetId
        );

        return new AgentResponseDTO(
            $errorMessage,
            new ActionsResponseDTO('', 'system', true),
            true, // hasError
            $e->getMessage()
        );
    }
}
