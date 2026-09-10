<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Models\AiPreset;
use App\Services\Agent\Skills\SkillToolGate;

/**
 * Builds the command-instructions prose block shown to the agent.
 *
 * Plugins' getDescription() and getInstructions() take the resolved per-preset
 * config as an argument, so descriptions can reflect what's actually enabled.
 *
 * Lazy-skills: SkillToolGate::hiddenFor($preset) returns the set of tool names owned
 * by a not-yet-loaded skill; those are dropped from this instruction block. Empty set
 * → nothing dropped → unchanged behavior. This is the tag-mode twin of the gate in
 * ToolSchemaBuilder; both must filter identically. Presentation gate only — a hidden
 * command stays fully callable (HIDING ≠ BLOCKING).
 */
class CommandInstructionBuilder implements CommandInstructionBuilderInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry,
        protected PluginManagerFactoryInterface $pluginManagerFactory,
        protected SkillToolGate $skillToolGate,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function buildInstructions(AiPreset $preset): string
    {
        $entries = $this->pluginManagerFactory->get()
            ->getEnabledPluginsWithContextsForPreset($preset);

        // Lazy-skills: compute the hidden set ONCE, drop those plugins before rendering.
        $hidden = $this->skillToolGate->hiddenFor($preset);
        if (!empty($hidden)) {
            $entries = array_filter(
                $entries,
                fn ($entry) => !in_array($entry['plugin']->getName(), $hidden, true)
            );
        }

        if (empty($entries)) {
            return "No commands available.";
        }

        $instructions = "MY AVAILABLE COMMANDS:\n\n";
        $instructions .= "I remember about the structure of the command system [command {optional method}]data if the command allows[/command].\n\n";

        foreach ($entries as $entry) {
            $plugin  = $entry['plugin'];
            $config  = $entry['context']->config;

            $instructions .= "Command: {$plugin->getName()}\n";
            $instructions .= "Description: {$plugin->getDescription($config)}\n";

            $pluginInstructions = $plugin->getInstructions($config);
            if (!empty($pluginInstructions)) {
                $instructions .= implode("\n", $pluginInstructions) . "\n";
            }

            $instructions .= str_repeat("-", 40) . "\n\n";
        }

        $instructions .= "MY CRITICAL RULES FOR COMMANDS:\n";
        $instructions .= "- NEVER write 'system_output_results' tag - system adds them automatically\n";
        $instructions .= "- NEVER invent or imitate the output of commands.\n";
        $instructions .= "- ALWAYS pay attention to the output of commands\n";
        $instructions .= "- Wait for real results before continuing\n\n";
        $instructions .= "- Always use proper command syntax with opening and closing tags!\n\n";

        return $instructions;
    }
}
