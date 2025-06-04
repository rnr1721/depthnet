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
        $knownPlugins = $this->pluginRegistry->getAvailablePluginNames();

        // First, extract all valid command blocks and replace them with placeholders
        $cleanedOutput = $this->excludeValidCommandBlocks($output, $knownPlugins);

        // 1. Check for unclosed tags
        $errors = array_merge($errors, $this->validateUnclosedTags($output, $knownPlugins));

        // 2. Check unknown commands ONLY outside valid blocks
        $errors = array_merge($errors, $this->validateUnknownCommands($cleanedOutput, $knownPlugins));

        // 3. Check nested commands
        $errors = array_merge($errors, $this->validateNestedCommands($output, $knownPlugins));

        return $errors;
    }

    /**
     * Replaces the contents of valid commands with placeholders
     *
     * @param string $output
     * @param array $knownPlugins
     * @return string
     */
    private function excludeValidCommandBlocks(string $output, array $knownPlugins): string
    {
        $cleanedOutput = $output;

        foreach ($knownPlugins as $plugin) {
            // Pattern for full command blocks with content
            $pattern = '/\[' . preg_quote($plugin) . '(?:\s+[a-z][a-z0-9_]*)?\](.*?)\[\/' . preg_quote($plugin) . '\]/s';

            $cleanedOutput = preg_replace_callback($pattern, function ($matches) use ($plugin) {
                // Replace the command contents with placeholder
                return '[' . $plugin . ']__CONTENT_PLACEHOLDER__[/' . $plugin . ']';
            }, $cleanedOutput);
        }

        return $cleanedOutput;
    }

    /**
     * Checks for unclosed tags
     *
     * @param string $output
     * @param array $knownPlugins
     * @return array
     */
    private function validateUnclosedTags(string $output, array $knownPlugins): array
    {
        $errors = [];
        $pluginPattern = implode('|', array_map('preg_quote', $knownPlugins));

        preg_match_all('/\[(' . $pluginPattern . ')(?: ([a-z][a-z0-9_]*))?\]/', $output, $openTags, PREG_OFFSET_CAPTURE);

        foreach ($openTags[0] as $index => $openTag) {
            $plugin = $openTags[1][$index][0];
            $method = isset($openTags[2][$index][0]) ? $openTags[2][$index][0] : null;
            $tagPosition = $openTag[1];

            $closePattern = '/\[\/' . preg_quote($plugin) . '\]/';
            $remainingText = substr($output, $tagPosition + strlen($openTag[0]));

            if (!preg_match($closePattern, $remainingText)) {
                $methodDisplay = $method ? " {$method}" : '';
                $errors[] = "ERROR: Command syntax error: [{$plugin}{$methodDisplay}] is missing closing tag [/{$plugin}]";
            }
        }

        return $errors;
    }

    /**
     * Checks for unknown commands, ignoring the contents of valid blocks
     *
     * @param string $cleanedOutput
     * @param array $knownPlugins
     * @return array
     */
    private function validateUnknownCommands(string $cleanedOutput, array $knownPlugins): array
    {
        $errors = [];

        // Find all opening tags
        preg_match_all('/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\]/', $cleanedOutput, $openingTags, PREG_SET_ORDER);

        // Find all closing tags
        preg_match_all('/\[\/([a-z][a-z0-9_]*)\]/', $cleanedOutput, $closingTags, PREG_SET_ORDER);

        // Check opening tags
        foreach ($openingTags as $tag) {
            $plugin = $tag[1];
            $method = isset($tag[2]) ? $tag[2] : null;

            // Skip placeholders
            if ($plugin === '__CONTENT_PLACEHOLDER__') {
                continue;
            }

            if (!in_array($plugin, $knownPlugins)) {
                $methodDisplay = $method ? " {$method}" : '';
                $errors[] = "WARNING: Unknown command: [{$plugin}{$methodDisplay}] - Available commands: " . implode(', ', $knownPlugins);
            }
        }

        // Check closing tags
        foreach ($closingTags as $tag) {
            $plugin = $tag[1];

            if (!in_array($plugin, $knownPlugins)) {
                $errors[] = "WARNING: Unknown closing command: [/{$plugin}] - Available commands: " . implode(', ', $knownPlugins);
            }
        }

        return $errors;
    }

    /**
     * Checks nested commands using a more robust approach
     *
     * @param string $output
     * @param array $knownPlugins
     * @return array
     */
    private function validateNestedCommands(string $output, array $knownPlugins): array
    {
        $errors = [];

        foreach ($knownPlugins as $plugin) {
            // Find all tags for this plugin
            $openPattern = '/\[' . preg_quote($plugin) . '(?:\s+[a-z][a-z0-9_]*)?\]/';
            $closePattern = '/\[\/' . preg_quote($plugin) . '\]/';

            preg_match_all($openPattern, $output, $openMatches, PREG_OFFSET_CAPTURE);
            preg_match_all($closePattern, $output, $closeMatches, PREG_OFFSET_CAPTURE);

            // Check for nesting by counting open/close tags
            $openCount = count($openMatches[0]);
            $closeCount = count($closeMatches[0]);

            if ($openCount > 1 && $closeCount > 1) {
                // More sophisticated check: ensure we don't have open->open->close->close pattern
                $positions = [];

                foreach ($openMatches[0] as $match) {
                    $positions[] = ['type' => 'open', 'pos' => $match[1], 'plugin' => $plugin];
                }

                foreach ($closeMatches[0] as $match) {
                    $positions[] = ['type' => 'close', 'pos' => $match[1], 'plugin' => $plugin];
                }

                // Sort by position
                usort($positions, fn ($a, $b) => $a['pos'] <=> $b['pos']);

                $stack = 0;
                foreach ($positions as $pos) {
                    if ($pos['type'] === 'open') {
                        $stack++;
                        if ($stack > 1) {
                            $errors[] = "WARNING: Nested commands of the same type [{$plugin}] detected - this may cause unexpected behavior";
                            break;
                        }
                    } else {
                        $stack--;
                    }
                }
            }
        }

        return $errors;
    }
}
