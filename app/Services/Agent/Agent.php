<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandValidatorInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\NotepadServiceInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;
use Psr\Log\LoggerInterface;

class Agent implements AgentInterface
{
    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected CommandParserInterface $commandParser,
        protected CommandExecutorInterface $commandExecutor,
        protected CommandValidatorInterface $commandValidator,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected PluginRegistryInterface $pluginRegistry,
        protected OptionsServiceInterface $optionsService,
        protected ChatStatusServiceInterface $chatStatusService,
        protected Message $messageModel,
        protected LoggerInterface $logger
    ) {
    }

    public function think(): Message
    {
        try {
            $context = $this->buildContext();
            $defaultPreset = $this->presetRegistry->getDefaultPreset();

            // Set plugins that disabled in this current preset for now
            $disabledPlugins = $defaultPreset->getPluginsDisabled();
            $this->pluginRegistry->setDisabledForNow($disabledPlugins);

            // Set current preset to all plugins
            $this->pluginRegistry->setCurrentPreset($defaultPreset);

            // Get engine for call model, using current preset ID
            $currentPresetId = $defaultPreset->getId();
            $currentEngine = $this->presetRegistry->createInstance($currentPresetId);

            // Get system prompt text
            $systemPrompt = $defaultPreset->getSystemPrompt();
            $thinkingPhrase = $this->optionsService->get('model_message_thinking_phrase', 'Thinking:');
            $replyFromModelLabel = $this->optionsService->get('model_reply_from_model', 'reply_from_model');
            $currentDopamineLevel = $defaultPreset->getDopamineLevel();
            $currentNotepadContent = $defaultPreset->getNotes();
            $commandInstructions = $this->commandInstructionBuilder->buildInstructions();

            $response = $currentEngine->generate(
                $context,
                $systemPrompt,
                $currentNotepadContent,
                $currentDopamineLevel,
                $commandInstructions
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

            // Parse commands using the dedicated service
            $commands = $this->commandParser->parse($output);

            $validationErrors = $this->commandValidator->validate($output);

            $visibleToUser = false;
            $role = 'thinking';

            if ($this->chatStatusService->isLoopedMode()) {
                if (str_contains($output, $replyFromModelLabel)) {
                    $visibleToUser = true;
                    $role = 'speaking';
                } else {
                    if (!str_starts_with($output, $thinkingPhrase)) {
                        $output = $thinkingPhrase . ' ' . $output;
                    }
                }
            }

            if (!empty($commands)) {
                // Execute commands using the dedicated service
                $role = 'command';
                $executionResult = $this->commandExecutor->executeCommands($commands, $output);
                $output = $executionResult->formattedMessage;
            }


            if (!empty($validationErrors)) {
                $output .= "\n\nCOMMAND SYNTAX ERRORS:\n";
                foreach ($validationErrors as $error) {
                    $output .= $error . "\n";
                }
            }

            return $this->messageModel->create([
                'role' => $role,
                'content' => $output,
                'from_user_id' => null,
                'is_visible_to_user' => $visibleToUser,
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
                'metadata' => $response->getMetadata()
            ]);
        }
    }

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

    /**
     * Get information about current preset
     */
    public function getCurrentPresetInfo(): array
    {
        try {
            $preset = $this->presetRegistry->getDefaultPreset();
            return [
                'id' => $preset->getId(),
                'name' => $preset->getName(),
                'description' => $preset->getDescription(),
                'engine_name' => $preset->getEngineName(),
                'is_default' => $preset->isDefault(),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to get preset info: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get available presets
     */
    public function getAvailablePresets(): array
    {
        try {
            $presets = $this->presetRegistry->getActivePresets();

            return $presets->map(function ($preset) {
                return [
                    'id' => $preset->getId(),
                    'name' => $preset->getName(),
                    'description' => $preset->getDescription(),
                    'engine_name' => $preset->getEngineName(),
                    'is_default' => $preset->isDefault(),
                ];
            })->toArray();
        } catch (\Exception $e) {
            $this->logger->error("Agent: Failed to get available presets", [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

}
