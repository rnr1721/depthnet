<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandParserInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Services\Agent\Plugins\DTO\ParsedCommand;

class CommandParserSmart implements CommandParserInterface
{
    public function __construct(
        protected PluginRegistryInterface $pluginRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function parse(string $output): array
    {
        $commands = [];

        // First, parse as usual
        $rawCommands = $this->parseRaw($output);

        // Then we glue together adjacent commands of the same type
        $commands = $this->mergeConsecutiveCommands($rawCommands);

        return $commands;
    }

    /**
     * Basic parsing
     *
     * @param string $output
     * @return array
     */
    protected function parseRaw(string $output): array
    {
        $commands = [];

        // Find all command blocks: [plugin method]content[/plugin] or [plugin]content[/plugin]
        preg_match_all('/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1\]/s', $output, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $plugin = strtolower(trim($match[1][0]));
            $method = isset($match[2][0]) && !empty(trim($match[2][0])) ? strtolower(trim($match[2][0])) : 'execute';
            $content = $this->cleanContent(trim($match[3][0]));
            $position = $match[0][1];

            $commands[] = new ParsedCommand($plugin, $method, $content, $position);
        }

        return $commands;
    }

    /**
     * Glues adjacent commands of the same type
     *
     * @param array $commands
     * @return array
     */
    protected function mergeConsecutiveCommands(array $commands): array
    {
        if (empty($commands)) {
            return $commands;
        }

        $merged = [];
        $currentCommand = null;

        foreach ($commands as $command) {
            if ($currentCommand === null) {
                $currentCommand = $command;
                continue;
            }

            // We check if it is possible to merge with the previous command
            if ($this->canMerge($currentCommand, $command)) {
                $currentCommand = $this->mergeCommands($currentCommand, $command);
            } else {
                $merged[] = $currentCommand;
                $currentCommand = $command;
            }
        }

        // Add the last command
        if ($currentCommand !== null) {
            $merged[] = $currentCommand;
        }

        return $merged;
    }

    /**
     * Checks if two commands can be glued together
     *
     * @param ParsedCommand $first
     * @param ParsedCommand $second
     * @return boolean
     */
    protected function canMerge(ParsedCommand $first, ParsedCommand $second): bool
    {
        // We glue only commands of the same type and method
        if ($first->plugin !== $second->plugin || $first->method !== $second->method) {
            return false;
        }

        try {
            $plugin = $this->pluginRegistry->get($first->plugin);
            return $plugin && $plugin->canBeMerged();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Glues two teams into one
     *
     * @param ParsedCommand $first
     * @param ParsedCommand $second
     * @return ParsedCommand
     */
    protected function mergeCommands(ParsedCommand $first, ParsedCommand $second): ParsedCommand
    {

        $plugin = $this->pluginRegistry->get($first->plugin);

        $separator = "\n";
        if ($plugin && method_exists($plugin, 'getMergeSeparator')) {
            $customSeparator = $plugin->getMergeSeparator();
            if ($customSeparator !== null) {
                $separator = $customSeparator;
            }
        }

        $mergedContent = $first->content . $separator . $second->content;

        // Bringing back a new team with unified content
        return new ParsedCommand(
            $first->plugin,
            $first->method,
            $mergedContent,
            $first->position // We leave the position of the first team
        );
    }

    /**
     * Cleans the content
     *
     * @param string $content
     * @return string
     */
    protected function cleanContent(string $content): string
    {
        // Remove PHP opening/closing tags if present
        $content = preg_replace('/^\s*<\?(php)?/i', '', $content);
        $content = preg_replace('/\s*\?>\s*$/i', '', $content);

        // Remove markdown code block markers
        $content = preg_replace('/^```(?:php|sql|)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);

        // Decode HTML entities
        $content = html_entity_decode($content);

        return trim($content);
    }
}
