<?php

namespace App\Services\Agent\Traits;

trait CommandPatternTrait
{
    /**
     * Regex pattern for matching command blocks.
     * Matches: [plugin method]content[/plugin] or [plugin method]content[/plugin method]
     */
    protected function getCommandPattern(): string
    {
        return '/\[([a-z][a-z0-9_]*)(?: ([a-z][a-z0-9_]*))?\](.*?)\[\/\1(?:\s+[a-z][a-z0-9_]*)?\]/s';
    }

    /**
     * Regex pattern for a specific plugin (used in preprocessor)
     */
    protected function getPluginPattern(string $plugin): string
    {
        return '/\[' . preg_quote($plugin) . '(?:\s+([a-z][a-z0-9_]*))?\](.*?)\[\/' . preg_quote($plugin) . '(?:\s+[a-z][a-z0-9_]*)?\]/s';
    }
}
