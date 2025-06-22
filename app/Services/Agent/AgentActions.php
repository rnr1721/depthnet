<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AiActionsResponseInterface;
use App\Contracts\Agent\CommandExecutorInterface;
use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\CommandLinterInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Services\Agent\DTO\ActionsResponseDTO;

class AgentActions implements AgentActionsInterface
{
    private const ROLE_USER = 'user';
    private const ROLE_THINKING = 'thinking';
    private const ROLE_SPEAKING = 'speaking';
    private const ROLE_COMMAND = 'command';

    public function __construct(
        protected CommandParserInterface $commandParser,
        protected CommandExecutorInterface $commandExecutor,
        protected CommandLinterInterface $commandLinter,
        protected ChatStatusServiceInterface $chatStatusService,
        protected OptionsServiceInterface $optionsService
    ) {

    }

    /**
     * @inheritDoc
     */
    public function runActions(string $responseString, bool $isUser = false): AiActionsResponseInterface
    {
        $commands = $this->commandParser->parse($responseString);

        $visibleToUser = false;
        $role = $isUser ? self::ROLE_USER : self::ROLE_THINKING;

        // If the command is not called by the user and if we work in a loop
        if (!$isUser) {
            $replyFromModelLabel = $this->optionsService->get('model_reply_from_model', 'reply_from_model');
            $thinkingPhrase = $this->optionsService->get('model_message_thinking_phrase', 'Thinking:');
            if (str_contains($responseString, $replyFromModelLabel)) {
                $visibleToUser = true;
                $role = self::ROLE_SPEAKING;
            } else {
                if (!str_starts_with($responseString, $thinkingPhrase)) {
                    $responseString = $thinkingPhrase . ' ' . $responseString;
                }
            }
        }

        $executionResult = $this->executeCommands($commands, $responseString);
        if ($executionResult) {
            $role = self::ROLE_COMMAND;
            $responseString = $executionResult;
        }

        $lintResults = $this->lintResults($responseString);
        if ($lintResults) {
            $responseString .= $lintResults;
        }

        return new ActionsResponseDTO(
            $responseString,
            $role,
            $visibleToUser
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
     * @param string $responseString
     * @return string|null
     */
    private function executeCommands(array $commands, string $responseString): ?string
    {
        if (empty($commands)) {
            return null;
        }

        $executionResult = $this->commandExecutor->executeCommands($commands, $responseString);
        return $executionResult->formattedMessage;
    }

}
