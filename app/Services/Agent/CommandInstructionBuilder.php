<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\PluginRegistryInterface;

class CommandInstructionBuilder implements CommandInstructionBuilderInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function buildInstructions(): string
    {
        $plugins = $this->pluginRegistry->all();

        if (empty($plugins)) {
            return "No commands available.";
        }

        $instructions = "AVAILABLE COMMANDS:\n\n";

        foreach ($plugins as $plugin) {
            $instructions .= "Command: {$plugin->getName()}\n";
            $instructions .= "Description: {$plugin->getDescription()}\n";
            $currentPluginInstructions = $plugin->getInstructions();
            $i = 1;
            foreach ($currentPluginInstructions as $instruction) {
                $instructions .= "Usage example ".$i.":\n{$instruction}\n";
                $i++;
            }

            $instructions .= str_repeat("-", 40) . "\n\n";
        }

        $instructions .= "IMPORTANT: Always use proper command syntax with opening and closing tags!";

        return $instructions;
    }
}
