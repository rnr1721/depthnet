<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
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
            // 1. Build context
            $context = $this->buildContext();

            // 2. Setup and generate response
            $response = $this->generateResponse($context);

            // 3. Handle response
            return $this->handleResponse($response);

        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Build context based on chat status
     *
     * @return array
     */
    protected function buildContext(): array
    {
        $mode = $this->chatStatusService->getChatStatus() ? self::MODE_CYCLE : self::MODE_SINGLE;
        $contextBuilder = $this->contextBuilderFactory->getContextBuilder($mode);

        return $contextBuilder->build();
    }

    /**
     * Generate AI response using current preset
     *
     * @param array $context
     * @return mixed
     */
    protected function generateResponse(array $context)
    {
        // Get and setup preset
        $defaultPreset = $this->presetRegistry->getDefaultPreset();
        $this->setupPresetEnvironment($defaultPreset);

        // Create engine and generate response
        $currentEngine = $this->presetRegistry->createInstance($defaultPreset->getId());

        return $currentEngine->generate(
            new ModelRequestDTO(
                $defaultPreset,
                $this->memoryService,
                $this->commandInstructionBuilder,
                $this->shortcodeManagerService,
                $context
            )
        );
    }

    /**
     * Setup preset environment (plugins, shortcodes)
     *
     * @param mixed $preset
     * @return void
     */
    protected function setupPresetEnvironment($preset): void
    {
        $this->pluginRegistry->setCurrentPreset($preset);
        $this->shortcodeManagerService->setDefaultShortcodes();
    }

    /**
     * Handle successful or error response
     *
     * @param mixed $response
     * @return Message
     */
    protected function handleResponse($response): Message
    {
        if ($response->isError()) {
            return $this->createSystemMessage(
                $response->getResponse(),
                $response->getMetadata()
            );
        }

        return $this->processSuccessfulResponse($response);
    }

    /**
     * Process successful AI response through actions
     *
     * @param mixed $response
     * @return Message
     */
    protected function processSuccessfulResponse($response): Message
    {
        $output = $response->getResponse();
        $actionsResult = $this->agentActions->runActions($output);

        return $this->messageModel->create([
            'role' => $actionsResult->getRole(),
            'content' => $actionsResult->getResult(),
            'from_user_id' => null,
            'is_visible_to_user' => $actionsResult->isVisibleForUser(),
            'metadata' => $response->getMetadata()
        ]);
    }

    /**
     * Create system message
     *
     * @param string $content
     * @param array $metadata
     * @return Message
     */
    protected function createSystemMessage(string $content, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role' => 'system',
            'content' => $content,
            'from_user_id' => null,
            'is_visible_to_user' => true,
            'metadata' => $metadata
        ]);
    }

    /**
     * Handle errors during thinking process
     *
     * @param \Exception $e
     * @return Message
     */
    protected function handleError(\Exception $e): Message
    {
        $this->logger->error("Agent: Error in think method", [
            'error_message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->createSystemMessage("Error in thinking process: " . $e->getMessage());
    }
}
