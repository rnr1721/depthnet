<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;
use App\Services\Agent\Plugins\DTO\ParsedCommand;
use App\Services\Agent\Plugins\DTO\CommandExecutionResult;

interface CommandExecutorInterface
{
    /**
     * Execute multiple commands
     *
     * @param ParsedCommand[] $commands
     * @param AiPreset $preset
     * @param AiPreset|null $mainPreset
     * @return CommandExecutionResult
     */
    public function executeCommands(array $commands, AiPreset $preset, ?AiPreset $mainPreset = null): CommandExecutionResult;
}
