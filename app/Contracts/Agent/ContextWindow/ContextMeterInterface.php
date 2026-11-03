<?php

namespace App\Contracts\Agent\ContextWindow;

use App\Models\Message;

/**
 * Measures how much of the context budget one history row costs.
 *
 * The context window is filled against a budget in the meter's units. Today
 * the only meter counts messages (every row costs 1), which reproduces the
 * classic max_context_limit semantics. The interface exists so the unit can
 * change without touching the window logic:
 *
 *   messages — 1 per row (current behaviour)
 *   chars    — length of the row as the provider will see it
 *   tokens   — chars scaled by a per-preset ratio calibrated from the
 *              provider's own usage.prompt_tokens (provider-agnostic: no
 *              tokenizer needed, the provider tells us what it counted)
 *
 * Whatever unit a meter uses, the watchdog compaction trigger must use the
 * same one — otherwise the window and the watchdog measure different things.
 */
interface ContextMeterInterface
{
    /**
     * Cost of a single history row, in this meter's units. Must be >= 1 for any
     * row that reaches the model, so a window can never grow without bound.
     */
    public function measure(Message $message): int;

    /**
     * Unit name for logs and UI: 'messages', 'chars', 'tokens'.
     */
    public function unit(): string;
}
