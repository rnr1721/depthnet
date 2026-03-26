<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

interface CommandPreRunnerInterface
{
    /**
     * Execute pre-commands defined in the preset and register results
     * as [[pre_command_results]] shortcode.
     *
     * Commands are read from $preset->getPreRunCommands() — a comma-separated
     * string like "memory show, workspace list, goal list".
     *
     * The shortcode is registered on $shortcodePreset (usually the same as
     * $executionPreset, but differs in the enricher: voice preset executes,
     * voice preset also receives the shortcode).
     *
     * If $mainPreset is provided, it is passed to AgentActions so that commands
     * listed in $mainPreset->getVoiceMpCommands() run in the main preset's
     * plugin context (e.g. MemoryManager writing to Adalia's memory).
     *
     * @param AiPreset      $executionPreset  Preset whose pre_run_commands are read and executed
     * @param AiPreset      $shortcodePreset  Preset the [[pre_command_results]] shortcode is registered for
     * @param AiPreset|null $mainPreset       Optional main preset for cross-context command routing
     * @return string
     */
    public function run(AiPreset $executionPreset, AiPreset $shortcodePreset, ?AiPreset $mainPreset = null): string;
}
