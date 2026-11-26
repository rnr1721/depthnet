<?php

namespace App\Services\Agent\Status;

use App\Contracts\Agent\Status\AgentStatusServiceInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * Cache-backed agent status with an in-process phase stack.
 *
 * Bind as a SINGLETON: the stack lives in this object for the duration of the
 * cycle running in the current worker process.
 *
 * Published snapshot (cache key agent_status_{presetId}):
 *   state        working | queued | sleeping | waiting | error | idle
 *   stack        [{phase, label, since_ms}]  — current nesting, outermost first
 *   steps        timeline of the running cycle (depth 1–2, ms = null while running)
 *   cycle_since  ms
 *   next_at      ms (sleeping)
 *   queued_at    ms (queued)
 *   error        string (error)
 *   last         {outcome, total_ms, ended_at, steps} — previous finished cycle
 *   updated_at   ms
 */
class AgentStatusService implements AgentStatusServiceInterface
{
    private const KEY          = 'agent_status_';
    private const STOP_KEY     = 'agent_status_stopping_';
    private const STORE_TTL    = 86400 * 7;
    private const RECORD_DEPTH = 2;
    private const MAX_STEPS    = 80;

    private ?int $presetId = null;

    /** @var array<int, array{phase: string, label: ?string, started: float, step: ?int}> */
    private array $stack = [];

    /** @var array<int, array{phase: string, label: ?string, depth: int, ms: ?int}> */
    private array $steps = [];

    private float $cycleStarted = 0.0;
    private bool $pendingNext = false;

    public function __construct(
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Cycle lifecycle ─────────────────────────────────────────────────────

    public function beginCycle(int $presetId): void
    {
        $this->safe(function () use ($presetId) {
            $this->presetId     = $presetId;
            $this->stack        = [];
            $this->steps        = [];
            $this->cycleStarted = microtime(true);
            $this->pendingNext  = false;
            $this->publish();
        });
    }

    public function endCycle(string $outcome, array $extra = []): void
    {
        $this->safe(function () use ($outcome, $extra) {
            if ($this->presetId === null) {
                return;
            }

            $now = microtime(true);

            // Close whatever is still open (exception paths).
            while ($this->stack) {
                $this->closeTop($now);
            }

            $presetId = $this->presetId;

            $last = [
                'outcome'  => $outcome,
                'total_ms' => (int) round(($now - $this->cycleStarted) * 1000),
                'ended_at' => $this->ms($now),
                'steps'    => $this->steps,
            ];

            // A continuation was requested during this cycle -> it's queued now.
            $state = $this->pendingNext ? 'queued' : $outcome;

            $snapshot = [
                'state'      => $state,
                'last'       => $last,
                'updated_at' => $this->ms($now),
            ];

            if ($state === 'queued') {
                $snapshot['queued_at'] = $this->ms($now);
            }
            if ($outcome === 'sleeping' && isset($extra['next_at'])) {
                $snapshot['next_at'] = (int) $extra['next_at'] * 1000;
            }
            if ($outcome === 'error' && isset($extra['error'])) {
                $snapshot['error'] = mb_strimwidth((string) $extra['error'], 0, 300, '…');
            }

            $this->cache->put(self::KEY . $presetId, $snapshot, self::STORE_TTL);
            $this->cache->forget(self::STOP_KEY . $presetId);

            $this->presetId    = null;
            $this->stack       = [];
            $this->steps       = [];
            $this->pendingNext = false;
        });
    }

    // ── Phases ──────────────────────────────────────────────────────────────

    public function track(string $phase, ?string $label, callable $fn): mixed
    {
        $entered = $this->enter($phase, $label);

        try {
            return $fn();
        } finally {
            if ($entered) {
                $this->leave();
            }
        }
    }

    public function annotate(string $label): void
    {
        $this->safe(function () use ($label) {
            if ($this->presetId === null || !$this->stack) {
                return;
            }
            $i = array_key_last($this->stack);
            $this->stack[$i]['label'] = $label;
            if ($this->stack[$i]['step'] !== null) {
                $this->steps[$this->stack[$i]['step']]['label'] = $label;
            }
            $this->publish();
        });
    }

    private function enter(string $phase, ?string $label): bool
    {
        if ($this->presetId === null) {
            return false;
        }

        $ok = false;
        $this->safe(function () use ($phase, $label, &$ok) {
            $depth = count($this->stack) + 1;
            $entry = [
                'phase'   => $phase,
                'label'   => $label !== null ? mb_strimwidth($label, 0, 80, '…') : null,
                'started' => microtime(true),
                'step'    => null,
            ];

            if ($depth <= self::RECORD_DEPTH && count($this->steps) < self::MAX_STEPS) {
                $this->steps[] = ['phase' => $phase, 'label' => $entry['label'], 'depth' => $depth, 'ms' => null];
                $entry['step'] = array_key_last($this->steps);
            }

            $this->stack[] = $entry;
            $this->publish();
            $ok = true;
        });

        return $ok;
    }

    private function leave(): void
    {
        $this->safe(function () {
            if ($this->presetId === null || !$this->stack) {
                return;
            }
            $this->closeTop(microtime(true));
            $this->publish();
        });
    }

    private function closeTop(float $now): void
    {
        $entry = array_pop($this->stack);
        if ($entry && $entry['step'] !== null && isset($this->steps[$entry['step']])) {
            $this->steps[$entry['step']]['ms'] = (int) round(($now - $entry['started']) * 1000);
        }
    }

    // ── Outside-of-cycle signals ────────────────────────────────────────────

    public function queued(int $presetId): void
    {
        $this->safe(function () use ($presetId) {
            // Our own cycle asked for a continuation: becomes "queued" at endCycle.
            if ($this->presetId === $presetId) {
                $this->pendingNext = true;
                return;
            }

            $snapshot = $this->read($presetId) ?? [];

            // Another worker is mid-cycle for this preset: don't clobber "working".
            if (($snapshot['state'] ?? null) === 'working' && !$this->isStale($snapshot)) {
                return;
            }

            $now = $this->ms(microtime(true));
            $this->cache->put(self::KEY . $presetId, array_merge($snapshot, [
                'state'      => 'queued',
                'queued_at'  => $now,
                'updated_at' => $now,
            ]), self::STORE_TTL);
        });
    }

    public function markStopping(int $presetId): void
    {
        $this->safe(fn () => $this->cache->put(self::STOP_KEY . $presetId, true, $this->staleAfterSeconds()));
    }

    // ── Read ────────────────────────────────────────────────────────────────

    public function get(int $presetId, bool $detailed = true): array
    {
        $now      = $this->ms(microtime(true));
        $snapshot = null;

        try {
            $snapshot = $this->read($presetId);
            $stopping = (bool) $this->cache->get(self::STOP_KEY . $presetId, false);
        } catch (\Throwable) {
            $stopping = false;
        }

        if (!$snapshot) {
            return ['state' => 'idle', 'now' => $now];
        }

        // Worker died mid-cycle: the snapshot is frozen in "working".
        if (($snapshot['state'] ?? null) === 'working' && $this->isStale($snapshot)) {
            $snapshot['state'] = 'unknown';
        } elseif ($stopping && ($snapshot['state'] ?? null) === 'working') {
            $snapshot['state'] = 'stopping';
        }

        $snapshot['now'] = $now;

        if (!$detailed) {
            $top = $snapshot['stack'][0] ?? null;
            return array_filter([
                'state'       => $snapshot['state'],
                'stack'       => $top ? [['phase' => $top['phase'], 'label' => null, 'since_ms' => $top['since_ms']]] : null,
                'cycle_since' => $snapshot['cycle_since'] ?? null,
                'queued_at'   => $snapshot['queued_at'] ?? null,
                'next_at'     => $snapshot['next_at'] ?? null,
                'now'         => $now,
            ], fn ($v) => $v !== null);
        }

        return $snapshot;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function publish(): void
    {
        if ($this->presetId === null) {
            return;
        }

        $previous = $this->read($this->presetId) ?? [];

        $this->cache->put(self::KEY . $this->presetId, [
            'state'       => 'working',
            'cycle_since' => $this->ms($this->cycleStarted),
            'stack'       => array_map(fn ($e) => [
                'phase'    => $e['phase'],
                'label'    => $e['label'],
                'since_ms' => $this->ms($e['started']),
            ], $this->stack),
            'steps'       => $this->steps,
            'last'        => $previous['last'] ?? null,
            'updated_at'  => $this->ms(microtime(true)),
        ], self::STORE_TTL);
    }

    private function read(int $presetId): ?array
    {
        $value = $this->cache->get(self::KEY . $presetId);
        return is_array($value) ? $value : null;
    }

    private function isStale(array $snapshot): bool
    {
        $updated = (int) ($snapshot['updated_at'] ?? 0);
        return $updated > 0 && ($this->ms(microtime(true)) - $updated) > $this->staleAfterSeconds() * 1000;
    }

    /** Same horizon as the cycle lock: past it, the cycle is certainly dead. */
    private function staleAfterSeconds(): int
    {
        return max(60, (int) config('ai.cycle_lock_ttl', 900));
    }

    private function ms(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->debug('AgentStatusService: ' . $e->getMessage());
        }
    }
}
