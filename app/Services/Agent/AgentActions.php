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
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;

class AgentActions implements AgentActionsInterface
{
    private const ROLE_USER = 'user';
    private const ROLE_THINKING = 'thinking';
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

        $output = '';

        $systemMessage = null;
        $visibleToUser = false;
        $role = $isUser ? self::ROLE_USER : self::ROLE_THINKING;

        $executionResult = $this->executeCommands($commands);
        if ($executionResult) {
            $role = self::ROLE_COMMAND;
            $output .= $executionResult->formattedMessage;
            if (isset($executionResult->pluginExecutionMeta['speak'])) {
                $systemMessage = $executionResult->pluginExecutionMeta['speak'];
                $visibleToUser = true;
            }
        }

        $lintResults = $this->lintResults($responseString);
        if ($lintResults) {
            $output .= $lintResults;
        }

        return new ActionsResponseDTO(
            $output,
            $role,
            $visibleToUser,
            $systemMessage
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
     * @return CommandExecutionResult|null
     */
    private function executeCommands(array $commands): ?CommandExecutionResult
    {
        if (empty($commands)) {
            return null;
        }

        return $this->commandExecutor->executeCommands($commands);
    }

}
