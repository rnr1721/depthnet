<?php

namespace App\Contracts\Agent\VectorMemory;

use App\Models\AiPreset;

/**
 * Contract for vector memory defragmentation.
 *
 * Defrag compresses raw memory records grouped by calendar day into a
 * smaller number of distilled summaries, keeping the memory store compact
 * and improving retrieval quality over time.
 */
interface DefragServiceInterface
{
    /**
     * Defragment vector memory for a single preset.
     *
     * Groups all memory records by calendar day (oldest first) and compresses
     * each day that has more entries than the configured keep-per-day threshold.
     * Days at or below the threshold are skipped — already compact.
     *
     * @param  AiPreset  $preset
     * @return array{
     *     days_processed: int,
     *     records_before: int,
     *     records_after: int,
     *     records_removed: int
     * }
     *
     * @throws \RuntimeException  If the preset's engine does not support defrag.
     */
    public function defrag(AiPreset $preset): array;

    /**
     * Load the default defrag prompt from data/defrag/default_prompt.txt.
     *
     * Used to pre-fill the defrag_prompt field when creating a new preset,
     * so the user sees a sensible starting point in the UI and can edit it.
     *
     * Comment lines (starting with #) are stripped before returning.
     *
     * @return string  The default prompt text with comment lines removed.
     *
     * @throws \RuntimeException  If the file is missing or unreadable.
     */
    public function getDefaultPrompt(): string;
}
