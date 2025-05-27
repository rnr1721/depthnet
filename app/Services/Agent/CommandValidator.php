<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandValidatorInterface;
use App\Contracts\Agent\PluginRegistryInterface;

class CommandValidator implements CommandValidatorInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(string $output): array
    {
        $errors = [];

        // Find all opening tags of known plugins
        $knownPlugins = $this->pluginRegistry->getAvailablePluginNames();
        $pluginPattern = implode('|', array_map('preg_quote', $knownPlugins));

        // 1. We are looking for unclosed tags: [plugin] or [plugin method] without [/plugin]
        preg_match_all('/\[(' . $pluginPattern . ')(?: ([a-z][a-z0-9_]*))?\]/', $output, $openTags, PREG_OFFSET_CAPTURE);

        foreach ($openTags[0] as $index => $openTag) {
            $plugin = $openTags[1][$index][0];
            $method = isset($openTags[2][$index][0]) ? $openTags[2][$index][0] : null;
            $tagPosition = $openTag[1];

            // We are looking for the corresponding closing tag
            $closePattern = '/\[\/' . preg_quote($plugin) . '\]/';
            $remainingText = substr($output, $tagPosition + strlen($openTag[0]));

            if (!preg_match($closePattern, $remainingText)) {
                $methodDisplay = $method ? " {$method}" : '';
                $errors[] = "ERROR: Command syntax error: [{$plugin}{$methodDisplay}] is missing closing tag [/{$plugin}]";
            }
        }

        // 2. Check for unknown commands (any tags that are not in known plugins)
        preg_match_all('/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\]/', $output, $allTags, PREG_SET_ORDER);

        foreach ($allTags as $tag) {
            $plugin = $tag[1];
            $method = isset($tag[2]) ? $tag[2] : null;

            if (!in_array($plugin, $knownPlugins)) {
                $methodDisplay = $method ? " {$method}" : '';
                $errors[] = "WARNING: Unknown command: [{$plugin}{$methodDisplay}] - Available commands: " . implode(', ', $knownPlugins);
            }
        }

        // 3. Check for nested commands of the same type
        foreach ($knownPlugins as $plugin) {
            $nestedPattern = '/\[' . preg_quote($plugin) . '(?:\s+[a-z][a-z0-9_]*)?\].*?\[' . preg_quote($plugin) . '(?:\s+[a-z][a-z0-9_]*)?\].*?\[\/' . preg_quote($plugin) . '\].*?\[\/' . preg_quote($plugin) . '\]/s';
            if (preg_match($nestedPattern, $output)) {
                $errors[] = "WARNING: Nested commands of the same type [{$plugin}] detected - this may cause unexpected behavior";
            }
        }

        // We can add other checks:
        // - Empty command content
        // - Unknown methods
        // - Nested commands of the same type

        return $errors;
    }
}
