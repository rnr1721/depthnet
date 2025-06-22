<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

class Agent implements AgentInterface
{
    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected AgentActionsInterface $agentActions,
        protected MemoryServiceInterface $memoryService,
        protected OptionsServiceInterface $optionsService,
        protected ShortcodeManagerServiceInterface $shortcodeManagerService,
        protected PluginRegistryInterface $pluginRegistry,
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
            $context = $this->buildContext();
            $defaultPreset = $this->presetRegistry->getDefaultPreset();

            $this->pluginRegistry->setCurrentPreset($defaultPreset);

            $this->shortcodeManagerService->setDefaultShortcodes();

            // Get engine for call model, using current preset ID
            $currentPresetId = $defaultPreset->getId();
            $currentEngine = $this->presetRegistry->createInstance($currentPresetId);

            $response = $currentEngine->generate(
                new ModelRequestDTO(
                    $defaultPreset,
                    $this->memoryService,
                    $this->commandInstructionBuilder,
                    $this->shortcodeManagerService,
                    $context
                )
            );

            if ($response->isError()) {
                return $this->messageModel->create([
                    'role' => 'system',
                    'content' => $response->getResponse(),
                    'from_user_id' => null,
                    'is_visible_to_user' => true,
                    'metadata' => $response->getMetadata()
                ]);
            }

            $output = $response->getResponse();

            $actionsResult = $this->agentActions->runActions($output);

            return $this->messageModel->create([
                'role' => $actionsResult->getRole(),
                'content' => $actionsResult->getResult(),
                'from_user_id' => null,
                'is_visible_to_user' => $actionsResult->isVisibleForUser(),
                'metadata' => $response->getMetadata()
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Agent: Error in think method", [
                'error_message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->messageModel->create([
                'role' => 'system',
                'content' => "Error in thinking process: " . $e->getMessage(),
                'from_user_id' => null,
                'is_visible_to_user' => true,
                'metadata' => []
            ]);
        }
    }

    /**
     * Get messages for context depends on config
     *
     * @return array
     */
    protected function buildContext(): array
    {
        $maxContextLimit = $this->optionsService->get('model_max_context_limit', 8);
        $messages = $this->messageModel->where('role', '!=', 'system')
            ->orderBy('created_at', 'desc')
            ->limit($maxContextLimit)
            ->get()
            ->reverse();

        $context = [];
        foreach ($messages as $message) {
            $context[] = [
                'role' => $message->role,
                'content' => $message->content,
                'from_user_id' => $message->from_user_id,
            ];
        }

        return $context;
    }

}
