<?php

namespace App\Services\Agent\ContextWindow;

/**
 * Splits a run of history rows into cycles and episodes. Pure: no DB, no
 * framework — the caller supplies the two classifiers.
 *
 * Cycle boundary:   every user-role row.
 * Episode boundary: a hard head (real input, see Message::isEpisodeHead), or
 *                   the first user row after a cycle in which the agent spoke
 *                   (soft head). Speech closes an episode: in a free-running
 *                   loop with no external input, the stretches between
 *                   utterances are the natural units ("from the start of a
 *                   response to the speech").
 *
 * Rows before the first user row become the prefix (see SegmentedHistory).
 */
final class EpisodeSegmenter
{
    /**
     * @param object[]               $rows       ascending by id, system rows excluded
     * @param callable(object): bool $isHardHead real input that opens an episode
     * @param callable(object): bool $didSpeak   agent row that carried speech outward
     */
    public function segment(array $rows, callable $isHardHead, callable $didSpeak): SegmentedHistory
    {
        $prefix   = [];
        $episodes = [];

        $open      = false;  // an episode is being collected
        $head      = null;
        $hard      = false;
        $cycles    = [];
        $cycle     = null;   // rows of the cycle being collected
        $prevSpoke = false;  // whether the last closed cycle spoke

        foreach ($rows as $row) {
            if (($row->role ?? null) !== 'user') {
                if ($cycle === null) {
                    $prefix[] = $row;
                } else {
                    $cycle[] = $row;
                }
                continue;
            }

            // A user row closes the previous cycle. For the very first user row,
            // the "previous cycle" is the orphan prefix: if it spoke, this row
            // opens a new episode with a known (soft) start.
            if ($cycle !== null) {
                $cycles[]  = $cycle;
                $prevSpoke = $this->cycleSpoke($cycle, $didSpeak);
            } elseif (!$open) {
                $prevSpoke = $this->cycleSpoke($prefix, $didSpeak);
            }

            $isHard   = $isHardHead($row);
            $boundary = $isHard || $prevSpoke;

            if (!$open || $boundary) {
                if ($open) {
                    $episodes[] = new Episode($head, $hard, $cycles);
                }

                $cycles = [];
                $open   = true;

                if ($isHard) {
                    $head = $row;
                    $hard = true;
                } elseif ($boundary) {
                    $head = $row;      // soft: known start, nothing to pin
                    $hard = false;
                } else {
                    $head = null;      // first fetched episode, start unknown
                    $hard = false;
                }
            }

            $cycle = [$row];
        }

        if ($cycle !== null) {
            $cycles[] = $cycle;
        }
        if ($open) {
            $episodes[] = new Episode($head, $hard, $cycles);
        }

        return new SegmentedHistory($prefix, $episodes);
    }

    /**
     * @param object[] $cycle
     */
    private function cycleSpoke(array $cycle, callable $didSpeak): bool
    {
        foreach ($cycle as $row) {
            if (($row->role ?? null) !== 'user' && $didSpeak($row)) {
                return true;
            }
        }

        return false;
    }
}
