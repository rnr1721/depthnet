<?php

namespace App\Contracts\Agent;

use App\Models\Message;

/**
 * Agent response interface for thinking operations
 *
 * Provides unified response structure for both successful operations
 * and handoff delegation between presets.
 */
interface AiAgentResponseInterface
{
    /**
     * Get the created message from agent thinking
     *
     * @return Message The database message entity
     */
    public function getMessage(): Message;

    /**
     * Get handoff delegation data if present
     *
     * @return array|null Handoff data containing:
     *   - target_preset: string - Target preset code
     *   - handoff_message: string|null - Optional delegation message
     *   - error_behavior: string - How to handle errors (stop|continue|fallback)
     */
    public function getHandoffData(): ?array;

    /**
     * Check if response contains handoff delegation
     *
     * @return bool True if agent is delegating to another preset
     */
    public function hasHandoff(): bool;

    /**
     * Check if response contains error
     *
     * @return bool True if thinking operation failed
     */
    public function hasError(): bool;

    /**
     * Get error message if present
     *
     * @return string|null Error description or null if no error
     */
    public function getErrorMessage(): ?string;
}
