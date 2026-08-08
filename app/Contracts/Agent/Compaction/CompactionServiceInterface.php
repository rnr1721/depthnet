<?php

namespace App\Contracts\Agent\Compaction;

use App\Models\AiPreset;
use App\Models\Message;

/**
 * CompactionService — memory consolidation for the active context window.
 *
 * Folds a range of active-window history into a single first-person recap:
 *   1. gathers the foldable range (active window minus the trailing recap)
 *   2. runs the preset's compressor preset over that range → summary text
 *   3. writes the summary to the journal as an episodic entry (the durable
 *      safety net: the agent will remember THAT a conversation happened and
 *      roughly about what, even if it crystallised nothing itself)
 *   4. writes a recap message back into the window (role=user,
 *      source=compaction, first person) so the next cycle continues from it
 *   5. marks the folded range compacted=true — the rows stay in the DB
 *      (visible to the user, reachable via journal/vector RAG), they just
 *      leave the active window
 *
 * The rows are never deleted. "Compaction", not truncation: the substrate
 * (journal + vector) is what makes folding the window non-destructive.
 */
interface CompactionServiceInterface
{
    /**
     * Run a compaction pass for the given preset.
     *
     * @param  AiPreset     $preset       The thinking preset whose window is folded.
     * @param  string|null  $focus        Optional focus/profile hint from the agent
     *                                     (e.g. "task-state" / "salience"), forwarded
     *                                     to the compressor prompt and used to override
     *                                     the journal entry type for this one pass.
     * @return Message|null  The recap message written into the window, or null when
     *                       compaction was a no-op (feature off, nothing to fold,
     *                       or compressor failed — all logged, never thrown).
     */
    public function compact(AiPreset $preset, ?string $focus = null): ?Message;

    /**
     * Count messages currently in the active window (compacted=false, non-system)
     * for this preset. Used by the watchdog trigger so the caller (Agent) doesn't
     * need to depend on the Message model just to size the window.
     */
    public function activeWindowCount(AiPreset $preset): int;
}
