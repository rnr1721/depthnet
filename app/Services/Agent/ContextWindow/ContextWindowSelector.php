<?php

namespace App\Services\Agent\ContextWindow;

use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\ContextWindow\ContextMeterInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Psr\Log\LoggerInterface;

/**
 * Episode-aware replacement for "last N messages of the active window".
 *
 * Reads history (active window, non-system) newest-first until it has a
 * comfortable margin over the budget, segments it into cycles and episodes,
 * lets WindowPlanner choose, and returns Message models ascending by id —
 * exactly what the context builders used to get from the raw query, so
 * everything downstream (cleaning, recap lift, RAG, injection) is unchanged.
 *
 * The planner is pure; this class only does the I/O around it:
 *   - fetching with a margin (the margin is what lets a cut "round up" to an
 *     episode start that lies just beyond the budget);
 *   - finding the real head of an episode longer than the fetched rows;
 *   - finding the latest recap;
 *   - annotating a pinned head with how many cycles were left out.
 *
 * Options (optionsService, all optional):
 *   agent_context_overfetch        float, default 2.0  — read up to budget × this
 *   agent_context_episode_overflow float, default 0.25 — an older episode may push
 *                                                       the window past the budget
 *                                                       by up to this share, once
 *   agent_context_head_scan_limit  int,   default 2000 — rows scanned backward to
 *                                                       find a head outside the fetch
 *   agent_context_previous_reserve float, default 0.3  — when the current episode overflows,
 *                                                       this share of the budget keeps what
 *                                                       came right before it, whole
 *   agent_context_outline          bool,  default true — list older episodes not shown in
 *                                                       full in one outline row
 *   agent_context_outline_share    float, default 0.3  — budget reserved for the outline
 *                                                       (normal mode)
 *   agent_context_outline_share_extended float, default 0.1 — same in extended (work) mode,
 *                                                       where recent steps need the room
 *   agent_context_outline_items    int,   default 10   — max episodes listed
 *   agent_context_outline_chars    int,   default 300  — input / reply length per item
 *
 * Empty cycles — a "Continue" answered with nothing (e.g. a reasoning model
 * that spent max_tokens on thinking) — are dropped before segmentation: they
 * carry no content and would otherwise eat the window slot by slot.
 */
class ContextWindowSelector
{
    /**
     * Appended to a pinned head when cycles of its episode are left out.
     * English on purpose, like CompactionService::RECAP_LABEL: it is addressed
     * to the model.
     */
    private const ELISION_NOTE = "\n\n[… %d intermediate cycle(s) of this episode are not shown here. "
        . "The message above is where it began; the most recent steps follow.]";

    private const DEFAULT_OVERFETCH       = 2.0;
    private const DEFAULT_OVERFLOW        = 0.25;
    private const DEFAULT_HEAD_SCAN_LIMIT = 2000;
    private const DEFAULT_PREVIOUS_RESERVE = 0.3;
    private const DEFAULT_OUTLINE_SHARE    = 0.3;
    private const DEFAULT_OUTLINE_SHARE_EXT = 0.1;
    private const DEFAULT_OUTLINE_ITEMS    = 10;
    private const DEFAULT_OUTLINE_CHARS    = 300;

    public function __construct(
        protected Message $messageModel,
        protected EpisodeSegmenter $segmenter,
        protected WindowPlanner $planner,
        protected EpisodeOutlineBuilder $outlineBuilder,
        protected OptionsServiceInterface $options,
        protected LoggerInterface $logger,
        protected ?ContextMeterInterface $meter = null,
        protected ?ContextModeResolverInterface $modeResolver = null,
    ) {
        $this->meter ??= new MessageCountMeter();
    }

    /**
     * Select the window for a preset under a budget (in the meter's units).
     */
    public function select(AiPreset $preset, int $budget): EloquentCollection
    {
        $budget    = max(1, $budget);
        $overfetch = $this->floatOption('agent_context_overfetch', self::DEFAULT_OVERFETCH, 1.0, 10.0);

        [$rows, $historyStartReached] = $this->fetch($preset, (int) ceil($budget * $overfetch));
        $rows = $this->dropEmptyCycles($rows);

        if ($rows === []) {
            return new EloquentCollection();
        }

        $earliestId = (int) $rows[0]->id;

        $isHead   = fn (Message $m): bool => $m->isEpisodeHead();
        $didSpeak = fn (Message $m): bool => $m->didSpeak();

        $history = $this->segmenter->segment($rows, $isHead, $didSpeak);

        // The current episode may be longer than what was fetched: its head is
        // then somewhere further back. Find it so it can be pinned.
        $current = $history->current();
        if ($current !== null
            && !$current->isComplete()
            && count($history->episodes) === 1
            && !$historyStartReached
        ) {
            $firstFetchedId = (int) $rows[0]->id;
            [$head, $gap] = $this->findDetachedHead($preset, $firstFetchedId);
            if ($head !== null) {
                $history = $history->withCurrent($current->withDetachedHead($head, $gap));
            }
        }

        // What came before the current episode feeds the outline (and, when the
        // current episode overflows, the previous-episode reserve). If the fetch
        // holds nothing complete before the current head (a long episode filled
        // it), read that part separately.
        $reserve     = $this->floatOption('agent_context_previous_reserve', self::DEFAULT_PREVIOUS_RESERVE, 0.0, 0.9);
        $outlineOn   = $this->boolOption('agent_context_outline', true);
        $current     = $history->current();

        if (($reserve > 0.0 || $outlineOn)
            && $current?->head !== null
            && !$historyStartReached
            && !$this->hasCompleteEpisodeBeforeCurrent($history)
        ) {
            [$older, $olderStart] = $this->fetch(
                $preset,
                (int) ceil($budget * $overfetch),
                (int) $current->head->id,
            );
            $older = $this->dropEmptyCycles($older);

            if ($older !== []) {
                $olderHistory = $this->segmenter->segment($older, $isHead, $didSpeak);
                $history = new SegmentedHistory(
                    $olderHistory->prefix,
                    array_merge($olderHistory->episodes, [$current]),
                );
                $historyStartReached = $olderStart;
                $earliestId          = min($earliestId, (int) $older[0]->id);
            }
        }

        $plan = $this->planner->plan(
            history:             $history,
            budget:              $budget,
            cost:                fn (Message $m): int => max(1, $this->meter->measure($m)),
            overflowRatio:       $this->floatOption('agent_context_episode_overflow', self::DEFAULT_OVERFLOW, 0.0, 1.0),
            historyStartReached: $historyStartReached,
            recap:               $this->latestRecap($preset),
            outline:             $outlineOn ? $this->outlineCallback($earliestId, $preset) : null,
            outlineShare:        $this->outlineShare($preset),
            outlineItems:        $this->intOption('agent_context_outline_items', self::DEFAULT_OUTLINE_ITEMS),
            previousReserve:     $reserve,
        );

        $selected = $plan->rows;

        if ($plan->pinnedHead !== null && $plan->elidedCycles > 0) {
            $selected = $this->annotatePinnedHead($selected, $plan->pinnedHead, $plan->elidedCycles);
        }

        $this->logger->debug('ContextWindowSelector: window selected', [
            'preset_id'      => $preset->getId(),
            'unit'           => $this->meter->unit(),
            'budget'         => $plan->budget,
            'spent'          => $plan->spent,
            'rows'           => count($selected),
            'episodes'       => $plan->episodesTaken,
            'pinned_head_id' => $plan->pinnedHead?->id,
            'elided_cycles'  => $plan->elidedCycles,
            'overflow_used'  => $plan->overflowUsed,
            'outlined'       => $plan->outlined,
        ]);

        return new EloquentCollection($selected);
    }

    /**
     * Read the active window newest-first in pages until the accumulated cost
     * reaches $target, or history runs out. With $beforeId, reading starts
     * just before that row (used for the context preceding the current episode).
     *
     * @return array{0: Message[], 1: bool} rows ascending, whether the start of history was reached
     */
    protected function fetch(AiPreset $preset, int $target, ?int $beforeId = null): array
    {
        $target   = max(1, $target);
        $pageSize = max(50, $target);

        $rows     = [];
        $spent    = 0;
        $reached  = false;

        while ($spent < $target) {
            $page = $this->baseQuery($preset)
                ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
                ->orderBy('id', 'desc')
                ->limit($pageSize)
                ->get();

            foreach ($page as $row) {
                $rows[] = $row;
                $spent += max(1, $this->meter->measure($row));
            }

            if ($page->count() < $pageSize) {
                $reached = true;
                break;
            }

            $beforeId = (int) $page->last()->id;
        }

        return [array_reverse($rows), $reached];
    }

    /**
     * Scan backward from just before the fetched rows for the hard head of the
     * current episode.
     *
     * Stops at the first of:
     *   - a hard head          → that is the head; returns it and the gap
     *   - an agent row that spoke → the episode started after it with a soft head;
     *                              nothing worth pinning
     *   - the scan limit        → give up (behaves like plain "last N")
     *
     * @return array{0: ?Message, 1: int} head, number of cycles between it and the fetched rows
     */
    protected function findDetachedHead(AiPreset $preset, int $beforeId): array
    {
        $limit        = $this->intOption('agent_context_head_scan_limit', self::DEFAULT_HEAD_SCAN_LIMIT);
        $scanned      = 0;
        $gap          = 0;
        $agentPending = false; // agent rows seen since the last user row (scanning backward)

        $cursor = $this->baseQuery($preset)
            ->where('id', '<', $beforeId)
            ->orderBy('id', 'desc')
            ->cursor();

        foreach ($cursor as $row) {
            if (++$scanned > $limit) {
                break;
            }

            if ($row->role === 'user') {
                if ($row->isEpisodeHead()) {
                    // The head's own cycle counts as elided if it had agent rows.
                    return [$row, $gap + ($agentPending ? 1 : 0)];
                }
                $gap++;
                $agentPending = false;
                continue;
            }

            if ($row->didSpeak()) {
                return [null, 0];
            }
            $agentPending = true;
        }

        return [null, 0];
    }

    /**
     * Outline builder for the planner. Speech rows are loaded lazily — only if
     * an outline is actually built — and once per selection.
     *
     * @param int $fromId earliest fetched row id (speech rows are read from here on)
     */
    protected function outlineCallback(int $fromId, AiPreset $preset): callable
    {
        $chars  = $this->intOption('agent_context_outline_chars', self::DEFAULT_OUTLINE_CHARS);
        $speech = null;

        return function (array $episodes, int $omitted) use ($preset, $fromId, $chars, &$speech): ?Message {
            $speech ??= $this->speechByAgentId($preset, $fromId);

            return $this->outlineBuilder->build($episodes, $omitted, $speech, $chars);
        };
    }

    /**
     * Outline share for the current mode: work mode keeps more room for the
     * agent's own recent steps.
     */
    protected function outlineShare(AiPreset $preset): float
    {
        $extended = $this->modeResolver?->isExtended($preset) ?? false;

        return $extended
            ? $this->floatOption('agent_context_outline_share_extended', self::DEFAULT_OUTLINE_SHARE_EXT, 0.0, 0.9)
            : $this->floatOption('agent_context_outline_share', self::DEFAULT_OUTLINE_SHARE, 0.0, 0.9);
    }

    /**
     * Spoken text of every cycle from $fromId on, keyed by the agent row it
     * belongs to. System rows are outside the window query, so they are read
     * here separately.
     *
     * @return array<int, string>
     */
    protected function speechByAgentId(AiPreset $preset, int $fromId): array
    {
        $map = [];

        $this->messageModel
            ->forPreset($preset->getId())
            ->where('role', 'system')
            ->where('id', '>=', $fromId)
            ->where('metadata->kind', Message::KIND_SPEECH)
            ->orderBy('id')
            ->get(['id', 'content', 'metadata'])
            ->each(function (Message $m) use (&$map) {
                $agentId = (int) ($m->metadata['agent_message_id'] ?? 0);
                if ($agentId > 0) {
                    $map[$agentId] = (string) $m->content;
                }
            });

        return $map;
    }

    /**
     * Drop cycles that are a continuation answered with nothing, and empty agent
     * rows anywhere. A real input whose answer was empty is kept (the input
     * itself still matters); the newest cycle, not answered yet, is kept too.
     *
     * @param Message[] $rows ascending
     * @return Message[]
     */
    protected function dropEmptyCycles(array $rows): array
    {
        $out   = [];
        $group = [];

        $flush = function () use (&$out, &$group): void {
            if ($group === []) {
                return;
            }

            $first   = $group[0];
            $isCycle = $first->role === 'user';
            $agent   = $isCycle ? array_slice($group, 1) : $group;
            $kept    = array_values(array_filter($agent, fn (Message $m) => !$this->isEmptyAgentRow($m)));

            $emptyContinuation = $isCycle
                && !$first->isEpisodeHead()
                && $agent !== []
                && $kept === [];

            if (!$emptyContinuation) {
                if ($isCycle) {
                    $out[] = $first;
                }
                array_push($out, ...$kept);
            }

            $group = [];
        };

        foreach ($rows as $row) {
            if ($row->role === 'user') {
                $flush();
            }
            $group[] = $row;
        }
        $flush();

        return $out;
    }

    private function isEmptyAgentRow(Message $m): bool
    {
        if ($m->role === 'user') {
            return false;
        }

        $meta = $m->metadata ?? [];

        return trim((string) $m->content) === ''
            && empty($meta['tool_calls_raw'])
            && empty($meta['tool_results']);
    }

    private function hasCompleteEpisodeBeforeCurrent(SegmentedHistory $history): bool
    {
        $episodes = $history->episodes;
        array_pop($episodes);

        foreach ($episodes as $ep) {
            if ($ep->isComplete()) {
                return true;
            }
        }

        return false;
    }

    protected function latestRecap(AiPreset $preset): ?Message
    {
        return $this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('role', 'user')
            ->where('metadata->source', Message::SOURCE_COMPACTION)
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Replace the pinned head with an in-memory copy that says how much is
     * missing. The copy is never saved.
     *
     * @param Message[] $rows
     * @return Message[]
     */
    protected function annotatePinnedHead(array $rows, Message $head, int $elided): array
    {
        $copy          = clone $head;
        $copy->content = rtrim((string) $head->content) . sprintf(self::ELISION_NOTE, $elided);

        $meta                  = $copy->metadata ?? [];
        $meta['elided_cycles'] = $elided;
        $copy->metadata        = $meta;

        foreach ($rows as $i => $row) {
            if ($row === $head || $row->id === $head->id) {
                $rows[$i] = $copy;
                break;
            }
        }

        return $rows;
    }

    protected function baseQuery(AiPreset $preset)
    {
        return $this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('role', '!=', 'system');
    }

    private function floatOption(string $key, float $default, float $min, float $max): float
    {
        $value = $this->options->get($key, $default);

        return is_numeric($value) ? min($max, max($min, (float) $value)) : $default;
    }

    private function boolOption(string $key, bool $default): bool
    {
        $value = $this->options->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function intOption(string $key, int $default): int
    {
        $value = $this->options->get($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
