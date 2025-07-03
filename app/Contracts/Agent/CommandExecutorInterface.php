<?php

namespace App\Contracts\Agent;

use App\Services\Agent\Plugins\DTO\ParsedCommand;
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;

interface CommandExecutorInterface
{
    /**
     * Execute multiple commands
     *
     * @param ParsedCommand[] $commands
     * @return CommandExecutionResult
     */
    public function executeCommands(array $commands): CommandExecutionResult;
}
