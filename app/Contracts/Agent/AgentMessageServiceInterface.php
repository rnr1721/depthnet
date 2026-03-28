<?php

namespace App\Contracts\Agent;

use App\Models\AiPreset;

/**
 * Service for delivering messages between agent presets.
 *
 * Handles inter-agent communication regardless of input mode:
 * - Pool mode: adds to the target preset's input pool, then materializes
 *   the full pool into a user message (JSON with source attribution)
 * - Plain mode: creates a user message in the target preset's history
 *
 * Tracks "reply-to" address so responses are automatically routed back
 * to the sender when the target preset doesn't issue an explicit handoff.
 */
interface AgentMessageServiceInterface
{
    /**
     * Deliver a message from one preset to another.
     *
     * Respects the target preset's input mode (pool vs plain).
     * Optionally records fromPreset as the reply-to address for the target.
     * Optionally triggers a thinking cycle for the target preset.
     *
     * @param AiPreset $fromPreset     Sending preset (used as source label)
     * @param AiPreset $toPreset       Receiving preset
     * @param string   $message        Message content to deliver
     * @param bool     $triggerThinking Whether to dispatch a thinking job for the target
     * @param bool     $setReplyTo     Whether to record sender as reply-to address
     */
    public function deliver(
        AiPreset $fromPreset,
        AiPreset $toPreset,
        string $message,
        bool $triggerThinking = true,
        bool $setReplyTo = true
    ): void;

    /**
     * Get the preset ID that this preset should reply to, if any.
     *
     * @param int $presetId The preset checking for a pending reply-to
     * @return int|null The sender's preset ID, or null if no reply expected
     */
    public function getReplyTo(int $presetId): ?int;

    /**
     * Clear the reply-to address after responding.
     *
     * @param int $presetId
     */
    public function clearReplyTo(int $presetId): void;
}
