<?php

namespace App\Services\Agent\ContextWindow;

/**
 * Decides which rows of a segmented history fill a budget. Pure: rows are
 * plain objects with ->id, costs come from a callable.
 *
 * Two passes — breadth first, then depth:
 *
 *   Outline.  Older episodes the window cannot show in full are still listed —
 *             one short line each (when, the input, what I answered) — in a
 *             single outline row built by the $outline callback. A share of the
 *             budget is reserved for it up front, so the agent always sees the
 *             shape of what came before, however much the current episode grows.
 *             Without it, a long current episode (e.g. searching for "how did it
 *             start") pushes out exactly the part it is looking for.
 *
 *   Detail.   The rest of the budget, newest first:
 *
 *     1. The latest recap is reserved (when not inside the current episode).
 *
 *     2. The current episode:
 *        - fits → taken whole;
 *        - does not fit → up to $previousReserve of the budget takes the
 *          episodes right before it whole (what it follows up on); then its
 *          hard head is pinned and the newest cycles are taken while they fit,
 *          the middle is elided. The newest cycle is always taken. A soft or
 *          unknown head is not pinned ("Continue" carries no task), so a
 *          free-running stretch degrades to "last N, snapped to a cycle".
 *
 *     3. When the current episode fit: older episodes newest to oldest, whole —
 *        within the remainder, or once over it by at most $overflowRatio of
 *        the budget ("round up" to the episode start); stop at the first that
 *        does not fit. Detail stays contiguous from the present backwards.
 *
 *     4. The prefix (agent rows before the first user turn) only at the true
 *        start of history, only if everything else was taken whole and fits.
 *
 *   Every older episode not taken whole goes into the outline (oldest first;
 *   if there are more than $outlineItems, the oldest one is kept as an anchor
 *   plus the newest ones, the gap is counted). Episodes whose start is unknown
 *   are never shown, in either pass. The recap's own episode is not outlined —
 *   the recap is present in full anyway.
 *
 * The window never starts inside a cycle.
 */
final class WindowPlanner
{
    /**
     * @param callable(object): int                    $cost
     * @param null|callable(Episode[], int): ?object  $outline builds the outline row from
     *                                                 episodes (oldest first) and the number
     *                                                 of episodes left out of it
     */
    public function plan(
        SegmentedHistory $history,
        int $budget,
        callable $cost,
        float $overflowRatio = 0.25,
        bool $historyStartReached = false,
        ?object $recap = null,
        ?callable $outline = null,
        float $outlineShare = 0.0,
        int $outlineItems = 10,
        float $previousReserve = 0.0,
    ): WindowPlan {
        $budget    = max(1, $budget);
        $remaining = $budget;
        $episodes  = array_values($history->episodes);

        if ($episodes === []) {
            $rows = [];
            if ($historyStartReached && $history->prefix !== []) {
                $rows = $this->tailThatFits($history->prefix, $budget, $cost);
            }
            return new WindowPlan($rows, null, 0, $this->sum($rows, $cost), $budget, 0, false);
        }

        $currentIdx = count($episodes) - 1;
        $current    = $episodes[$currentIdx];

        // Older episodes with a known start, newest first.
        $older = [];
        for ($i = $currentIdx - 1; $i >= 0; $i--) {
            $ep = $episodes[$i];
            if (!($ep->isComplete() || ($i === 0 && $historyStartReached))) {
                break;
            }
            $older[] = $ep;
        }

        // Recap reserve.
        if ($recap !== null && !$this->contains($current->rows(), $recap)) {
            $remaining -= $cost($recap);
        }

        // Outline reserve — taken back before the outline is built and charged
        // at its real cost then.
        $outlineReserve = 0;
        if ($outline !== null && $older !== [] && $outlineShare > 0.0) {
            $outlineReserve = max(1, (int) floor($budget * min(0.9, $outlineShare)));
            $remaining     -= $outlineReserve;
        }

        $selected     = [];
        $pinnedHead   = null;
        $elided       = 0;
        $taken        = 0;
        $overflowUsed = false;
        $wholeOlder   = 0; // how many of $older (from the newest) went in whole

        // Current episode.
        $currentCost = $this->sum($current->rows(), $cost);

        if (!$current->headDetached && $currentCost <= $remaining) {
            array_push($selected, ...$current->rows());
            $remaining -= $currentCost;
            $taken++;

            $allowance = (int) floor($budget * max(0.0, $overflowRatio));

            foreach ($older as $ep) {
                $c = $this->sum($ep->rows(), $cost);

                if ($c <= $remaining) {
                    array_push($selected, ...$ep->rows());
                    $remaining -= $c;
                    $taken++;
                    $wholeOlder++;
                    continue;
                }

                if (!$overflowUsed && $c <= $remaining + $allowance) {
                    array_push($selected, ...$ep->rows());
                    $remaining -= $c;
                    $taken++;
                    $wholeOlder++;
                    $overflowUsed = true;
                }
                break;
            }
        } else {
            // What it follows up on, whole, within the reserve.
            $reserve = (int) floor($budget * min(0.9, max(0.0, $previousReserve)));

            foreach ($older as $ep) {
                $c = $this->sum($ep->rows(), $cost);
                if ($c > $reserve || $c > $remaining) {
                    break;
                }
                array_push($selected, ...$ep->rows());
                $reserve   -= $c;
                $remaining -= $c;
                $taken++;
                $wholeOlder++;
            }

            [$rows, $pinnedHead, $elided, $remaining] = $this->planOverflowingEpisode($current, $remaining, $cost);
            array_push($selected, ...$rows);
            $taken++;
        }

        // Prefix — only at the real beginning, only if every older episode is whole.
        $allOlderWhole = $wholeOlder === count($older) && count($older) === $currentIdx;
        if ($allOlderWhole && $historyStartReached && $history->prefix !== []) {
            $pc = $this->sum($history->prefix, $cost);
            if ($pc <= max(0, $remaining)) {
                array_push($selected, ...$history->prefix);
                $remaining -= $pc;
            }
        }

        // Outline of everything older that is not shown whole.
        $remaining += $outlineReserve;
        $outlined   = 0;

        $rest = array_slice($older, $wholeOlder);
        if ($recap !== null) {
            $rest = array_values(array_filter(
                $rest,
                fn (Episode $ep) => !($ep->head !== null && $this->contains([$ep->head], $recap))
            ));
        }

        if ($outline !== null && $rest !== []) {
            $items   = array_reverse($rest); // oldest first
            $omitted = 0;

            $max = max(1, $outlineItems);
            if (count($items) > $max) {
                $omitted = count($items) - $max;
                $items   = array_merge([$items[0]], array_slice($items, -($max - 1)));
            }

            $row = $outline($items, $omitted);
            while ($row !== null && $cost($row) > max(0, $remaining) && count($items) > 1) {
                array_splice($items, count($items) > 2 ? 1 : 0, 1); // drop just after the anchor
                $omitted++;
                $row = $outline($items, $omitted);
            }

            if ($row !== null && $cost($row) <= max(0, $remaining)) {
                $selected[] = $row;
                $remaining -= $cost($row);
                $outlined   = count($items);
            }
        }

        if ($recap !== null && !$this->contains($selected, $recap)) {
            $selected[] = $recap;
        }

        $selected = $this->sortById($selected);

        return new WindowPlan(
            $selected,
            $elided > 0 ? $pinnedHead : null,
            $elided,
            $this->sum($selected, $cost),
            $budget,
            $taken,
            $overflowUsed,
            $outlined,
        );
    }

    /**
     * Current episode that does not fit: pin the hard head, keep the newest
     * cycles that fit, elide the middle.
     *
     * @return array{0: object[], 1: ?object, 2: int, 3: int}
     */
    private function planOverflowingEpisode(Episode $ep, int $remaining, callable $cost): array
    {
        $cycles = $ep->cycles;
        $n      = count($cycles);
        $pin    = $ep->isPinnable();

        // When the head is in the fetched rows, cycle 0 holds it: the head row is
        // pinned on its own, the rest of cycle 0 competes like any other cycle
        // only if it is reached (it rarely is — that is the point).
        $firstCandidate = ($pin && !$ep->headDetached) ? 1 : 0;

        $rows = [];
        if ($pin) {
            $rows[]     = $ep->head;
            $remaining -= $cost($ep->head);
        }

        $firstKept = $n; // nothing kept yet
        for ($i = $n - 1; $i >= $firstCandidate; $i--) {
            $c = $this->sum($cycles[$i], $cost);

            // The newest cycle is always kept: it is the turn being answered.
            if ($firstKept === $n || $c <= $remaining) {
                $firstKept  = $i;
                $remaining -= $c;
                continue;
            }
            break;
        }

        for ($i = $firstKept; $i < $n; $i++) {
            array_push($rows, ...$cycles[$i]);
        }

        // Cycles whose content is missing between the pinned head and the kept tail.
        $elided = 0;
        if ($pin) {
            $elided = $firstKept - $firstCandidate;
            if ($ep->headDetached) {
                $elided += $ep->detachedGap;
            } elseif (count($cycles[0] ?? []) > 1) {
                $elided += 1; // the head's own cycle lost its agent rows
            }
        }

        return [$rows, $pin ? $ep->head : null, $elided, $remaining];
    }

    /**
     * Newest rows of a flat list that fit a budget (used only for a
     * prefix-only history).
     *
     * @param object[] $rows
     * @return object[]
     */
    private function tailThatFits(array $rows, int $budget, callable $cost): array
    {
        $out = [];
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $c = $cost($rows[$i]);
            if ($out !== [] && $c > $budget) {
                break;
            }
            array_unshift($out, $rows[$i]);
            $budget -= $c;
        }
        return $out;
    }

    /**
     * @param object[] $rows
     */
    private function sum(array $rows, callable $cost): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += $cost($row);
        }
        return $total;
    }

    /**
     * @param object[] $rows
     */
    private function contains(array $rows, object $needle): bool
    {
        foreach ($rows as $row) {
            if ($row === $needle || (isset($row->id, $needle->id) && $row->id === $needle->id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param object[] $rows
     * @return object[]
     */
    private function sortById(array $rows): array
    {
        usort($rows, fn ($a, $b) => ($a->id ?? 0) <=> ($b->id ?? 0));
        return $rows;
    }
}
