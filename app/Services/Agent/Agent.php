<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandValidatorInterface;
use App\Contracts\Agent\ModelRegistryInterface;
use App\Contracts\Agent\Plugins\NotepadServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class Agent implements AgentInterface
{
    public function __construct(
        protected ModelRegistryInterface $modelRegistry,
        protected CommandParserInterface $commandParser,
        protected CommandExecutorInterface $commandExecutor,
        protected CommandValidatorInterface $commandValidator,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected NotepadServiceInterface $notepadService,
        protected OptionsServiceInterface $optionsService,
        protected Message $messageModel,
    ) {
    }

    public function think(): Message
    {
        try {
            $context = $this->buildContext();
            $currentModelName = $this->optionsService->get('model_default', 'mock');
            $model = $this->modelRegistry->get($currentModelName);

            $thinkingPhrase = $this->optionsService->get('model_message_thinking_phrase', 'Thinking:');
            $mode = $this->optionsService->get('model_agent_mode', 'looped');
            $replyFromModelLabel = $this->optionsService->get('model_reply_from_model', 'reply_from_model');
            $initialMessageRaw = $this->optionsService->get('system_start_message', '');
            $currentDophamineLevel = intval($this->optionsService->get('plugin_dophamine', 5));
            $currentNotepadContent = $this->notepadService->getNotepad();
            $commandInstructions = $this->commandInstructionBuilder->buildInstructions();

            $output = $model->generate(
                $context,
                $initialMessageRaw,
                $currentNotepadContent,
                $currentDophamineLevel,
                $commandInstructions
            );

            if (empty($output)) {
                return $this->messageModel->create([
                    'role' => 'system',
                    'content' => "empty message",
                    'from_user_id' => null,
                    'is_visible_to_user' => true
                ]);
            }

            // Parse commands using the dedicated service
            $commands = $this->commandParser->parse($output);

            $validationErrors = $this->commandValidator->validate($output);

            $visibleToUser = false;
            $role = 'thinking';

            if ($mode === 'looped') {
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
                'is_visible_to_user' => $visibleToUser
            ]);

        } catch (\Exception $e) {
            Log::error("Agent: Error in think method", [
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
                'is_visible_to_user' => true
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

}
