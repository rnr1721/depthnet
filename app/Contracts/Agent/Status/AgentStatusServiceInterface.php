<?php

namespace App\Contracts\Agent\Status;

/**
 * What the agent is doing right now — for the chat UI.
 *
 * A worker process runs one thinking cycle at a time, so the service keeps the
 * "current cycle" in memory (it MUST be bound as a singleton) and publishes a
 * small snapshot to the cache on every phase change. Code deep inside the cycle
 * (RAG, voices, plugins) just calls track() without knowing which preset owns
 * the cycle; outside a cycle (web requests, WarmRag) every call is a no-op.
 *
 * Phases nest (context → rag → rag_query). The two top levels are recorded into
 * a timeline with durations; deeper levels only show up as the live "sub" label.
 *
 * Never throws: status is observability, it must not be able to break a cycle.
 */
interface AgentStatusServiceInterface
{
    /** Start a cycle for this preset in the current process. */
    public function beginCycle(int $presetId): void;

    /**
     * Finish the cycle. $outcome: waiting | sleeping | retrying | error.
     * $extra: next_at (unix seconds) for sleeping/retrying, error (string),
     *         attempt / max_attempts (retrying), retryable / kind / gave_up (error).
     */
    public function endCycle(string $outcome, array $extra = []): void;

    /**
     * Run $fn inside a phase. Returns what $fn returns, rethrows what it throws.
     */
    public function track(string $phase, ?string $label, callable $fn): mixed;

    /** Set/replace the label of the innermost running phase (e.g. "cache: warm"). */
    public function annotate(string $label): void;

    /** A job for this preset was dispatched (or will be right after the current cycle). */
    public function queued(int $presetId): void;

    /** Stop was requested; the running cycle will still finish. */
    public function markStopping(int $presetId): void;

    /**
     * Snapshot for the UI.
     *
     * @param bool $detailed false: only state/phase/timing (no labels, no timeline)
     */
    public function get(int $presetId, bool $detailed = true): array;
}
