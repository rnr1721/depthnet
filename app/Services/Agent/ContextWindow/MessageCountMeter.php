<?php

namespace App\Services\Agent\ContextWindow;

use App\Contracts\Agent\ContextWindow\ContextMeterInterface;
use App\Models\Message;

/**
 * Every row costs 1 — the classic "last N messages" unit. With this meter the
 * episode-aware window keeps max_context_limit's meaning; it only moves where
 * the cut lands.
 */
final class MessageCountMeter implements ContextMeterInterface
{
    public function measure(Message $message): int
    {
        return 1;
    }

    public function unit(): string
    {
        return 'messages';
    }
}
