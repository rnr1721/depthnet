<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface for providers that can report what arrived since the
 * previous check.
 *
 * The cursor is opaque to the caller: store what comes back in
 * UpdatesBatch::$cursor and pass it in next time. The caller owns persistence
 * on purpose — save the cursor only AFTER the batch has been processed, and a
 * crash in between replays the batch instead of losing it (at-least-once).
 *
 * An empty cursor means "first run": providers take a baseline and report
 * nothing (UpdatesBatch::$baseline = true), rather than replaying the whole
 * account history into the agent.
 *
 * Outgoing messages (sent from this account) are not reported. Filtering by
 * sender, whitelist or chat type is the caller's job — see InboundSettings.
 */
interface ReceivesMessagesInterface
{
    /**
     * @param  array<string, mixed> $cursor    Opaque cursor from the previous batch, or [].
     * @param  int                  $limit     Soft cap on messages in one batch.
     * @param  int                  $perDialog Max messages taken from one chat in one batch.
     * @return MessengerResult value: UpdatesBatch
     */
    public function fetchUpdates(array $cursor, int $limit = 100, int $perDialog = 20): MessengerResult;
}
