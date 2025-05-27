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
     * @param string $originalOutput
     * @return CommandExecutionResult
     */
    public function executeCommands(array $commands, string $originalOutput): CommandExecutionResult;
}
