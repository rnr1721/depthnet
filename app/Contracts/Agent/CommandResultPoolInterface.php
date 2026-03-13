<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;
use App\Models\Message;

interface CommandResultPoolInterface
{
    /**
     * Add a new result entry to the pool, linked to the given message.
     * Automatically prunes entries whose linked messages have fallen
     * outside the current context window (max_context_limit).
     *
     * @param AiPreset $preset
     * @param Message  $message  The 'command' role message this result belongs to
     * @param string   $results
     * @return void
     */
    public function push(AiPreset $preset, Message $message, string $results): void;

    /**
     * Get all result entries for the given preset, oldest first.
     *
     * @param AiPreset $preset
     * @return string[]
     */
    public function getAll(AiPreset $preset): array;

    /**
     * Get all results concatenated into a single string, separated by a divider.
     * Ready to be injected into a system prompt placeholder.
     *
     * @param AiPreset $preset
     * @return string
     */
    public function getFormatted(AiPreset $preset): string;

    /**
     * Clear the entire pool for the given preset.
     *
     * @param AiPreset $preset
     * @return void
     */
    public function clear(AiPreset $preset): void;
}
