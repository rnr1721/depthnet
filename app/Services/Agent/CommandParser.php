<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandParserInterface;
use App\Services\Agent\Plugins\DTO\ParsedCommand;

class CommandParser implements CommandParserInterface
{
    /**
     * @inheritDoc
     */
    public function parse(string $output): array
    {
        $commands = [];
        $position = 0;

        // Find all command blocks: [plugin method]content[/plugin] or [plugin]content[/plugin]
        preg_match_all('/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1\]/s', $output, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $plugin = strtolower(trim($match[1][0]));
            $method = isset($match[2][0]) && !empty(trim($match[2][0])) ? strtolower(trim($match[2][0])) : 'execute';
            $content = $this->cleanContent(trim($match[3][0]));
            $position = $match[0][1]; // Position in original string

            $commands[] = new ParsedCommand($plugin, $method, $content, $position);
        }

        return $commands;
    }

    /**
     * Cleans the content by removing unnecessary tags and decoding HTML entities.
     *
     * @param string $content The content to clean.
     * @return string The cleaned content.
     */
    protected function cleanContent(string $content): string
    {
        // Remove PHP opening/closing tags if present
        $content = preg_replace('/^\s*<\?(php)?/i', '', $content);
        $content = preg_replace('/\?>\s*$/i', '', $content);

        // Remove markdown code block markers
        $content = preg_replace('/^```(?:php|sql|)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);

        // Decode HTML entities
        $content = html_entity_decode($content);

        return trim($content);
    }
}
