<?php

namespace App\Services\Agent\Wake;

use App\Contracts\Agent\PulseServiceInterface;
use App\Contracts\Agent\Wake\WakeScheduleServiceInterface;
use App\Models\AiPreset;
use App\Models\WakeSchedule;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Default WakeScheduleServiceInterface implementation.
 *
 * Owns the three things that must live in exactly one place:
 *   - next_run_at computation (including the cron library)
 *   - validation of structured input
 *   - pulse ↔ clock presentation for the schedule block
 *
 * Storage is always wall-clock / seconds (see migration). Pulses only appear
 * on the way in (via WakeExpressionParser) and on the way out (renderScheduleBlock).
 */
class WakeScheduleService implements WakeScheduleServiceInterface
{
    public function __construct(
        protected WakeExpressionParser $parser,
        protected PulseServiceInterface $pulse,
        protected LoggerInterface $logger,
    ) {
    }

    public function createFromExpression(
        AiPreset $preset,
        string $expression,
        bool $pulsesEnabled,
        string $createdByKind = WakeSchedule::KIND_AGENT,
    ): WakeSchedule {
        $parsed = $this->parser->parse($expression, $pulsesEnabled);

        if (!$parsed->isValid()) {
            throw new WakeScheduleException($parsed->error);
        }

        $attributes = $parsed->toAttributes();
        $this->validateStructured($attributes);

        return $this->persist($preset, $attributes, $createdByKind);
    }

    public function createFromAttributes(
        AiPreset $preset,
        array $attributes,
        string $createdByKind = WakeSchedule::KIND_USER,
    ): WakeSchedule {
        $this->validateStructured($attributes);
        return $this->persist($preset, $attributes, $createdByKind);
    }

    public function update(WakeSchedule $schedule, array $attributes): WakeSchedule
    {
        // Merge, then re-validate the timing shape as a whole.
        $merged = array_merge($schedule->only([
            'schedule_type', 'run_at', 'interval_seconds', 'daily_seconds', 'cron_expression', 'wake_message',
        ]), array_filter($attributes, fn ($v) => $v !== null));

        $this->validateStructured($merged);

        $schedule->fill($merged);
        $schedule->next_run_at = $this->computeNextRun($schedule, Carbon::now());
        $schedule->save();

        return $schedule;
    }

    public function cancel(WakeSchedule $schedule): void
    {
        $schedule->delete();
    }

    public function listForPreset(AiPreset $preset, bool $includeDisabled = true): Collection
    {
        $query = WakeSchedule::forPreset($preset->getId())
            ->orderByRaw('next_run_at IS NULL, next_run_at ASC');

        if (!$includeDisabled) {
            $query->where('enabled', true);
        }

        return $query->get();
    }

    public function due(): Collection
    {
        return WakeSchedule::due(Carbon::now())->get();
    }

    public function recomputeAfterFire(WakeSchedule $schedule): void
    {
        $now = Carbon::now();
        $schedule->last_fired_at = $now;

        if (!$schedule->isRecurring()) {
            // once → its single moment has passed.
            $schedule->enabled     = false;
            $schedule->next_run_at = null;
            $schedule->save();
            return;
        }

        // Recurring: compute the NEXT fire strictly after now, so a slow tick
        // that fired late doesn't immediately re-fire.
        $schedule->next_run_at = $this->computeNextRun($schedule, $now, strictlyAfter: true);
        $schedule->save();
    }

    public function renderScheduleBlock(AiPreset $preset, bool $pulsesEnabled): string
    {
        $rows = $this->listForPreset($preset, includeDisabled: false)
            ->filter(fn (WakeSchedule $s) => $s->next_run_at !== null);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['[WAKE_SCHEDULE]'];

        foreach ($rows as $s) {
            $when  = $this->describeWhen($s, $pulsesEnabled);
            $who   = $s->created_by_kind === WakeSchedule::KIND_USER ? 'user' : 'mine';
            $msg   = $this->truncate((string) $s->wake_message, 60);
            $lines[] = sprintf('#%d  %-22s → "%s"  (%s)', $s->getId(), $when, $msg, $who);
        }

        $lines[] = '[/WAKE_SCHEDULE]';

        return implode("\n", $lines);
    }

    // ── internals ────────────────────────────────────────────────────────────

    protected function persist(AiPreset $preset, array $attributes, string $createdByKind): WakeSchedule
    {
        $schedule = new WakeSchedule(array_merge($attributes, [
            'preset_id'       => $preset->getId(),
            'enabled'         => true,
            'created_by_kind' => $createdByKind,
        ]));

        // Initial next_run_at is the FIRST fire — for daily/cron this may be
        // today or tomorrow; computeNextRun handles the "already passed today"
        // case by rolling forward.
        $schedule->next_run_at = $this->computeNextRun($schedule, Carbon::now());
        $schedule->save();

        return $schedule;
    }

    /**
     * Compute the next fire moment for a schedule relative to $from.
     *
     * @param bool $strictlyAfter When true, the result is guaranteed to be
     *                            strictly greater than $from (used after a fire
     *                            so we don't re-fire the same tick).
     */
    protected function computeNextRun(WakeSchedule $s, Carbon $from, bool $strictlyAfter = false): ?Carbon
    {
        return match ($s->schedule_type) {
            WakeSchedule::TYPE_ONCE => $s->run_at,

            WakeSchedule::TYPE_INTERVAL => (clone $from)->addSeconds(max(1, (int) $s->interval_seconds)),

            WakeSchedule::TYPE_DAILY => $this->nextDaily((int) $s->daily_seconds, $from, $strictlyAfter),

            WakeSchedule::TYPE_CRON => $this->nextCron((string) $s->cron_expression, $from),

            default => null,
        };
    }

    protected function nextDaily(int $secondsFromMidnight, Carbon $from, bool $strictlyAfter): Carbon
    {
        $candidate = (clone $from)->startOfDay()->addSeconds($secondsFromMidnight);

        // If today's slot is already at/behind us, roll to tomorrow.
        if ($candidate->lte($from) || ($strictlyAfter && $candidate->eq($from))) {
            $candidate->addDay();
        }

        return $candidate;
    }

    protected function nextCron(string $expr, Carbon $from): ?Carbon
    {
        try {
            $cron = new CronExpression($expr);
            return Carbon::instance($cron->getNextRunDate($from));
        } catch (\Throwable $e) {
            $this->logger->warning('WakeScheduleService: invalid cron at compute time', [
                'expr'  => $expr,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Validate the timing shape. Throws WakeScheduleException with an
     * agent-readable message. Ensures the row carries exactly the fields its
     * type needs.
     */
    protected function validateStructured(array $a): void
    {
        $type = $a['schedule_type'] ?? null;

        if (trim((string) ($a['wake_message'] ?? '')) === '') {
            // A wake with no instruction wakes the agent into a void. Allowed but
            // warned — some agents may want a bare "just look around" pulse. Keep
            // it permissive; the plugin can enforce stricter if desired.
        }

        switch ($type) {
            case WakeSchedule::TYPE_ONCE:
                if (empty($a['run_at'])) {
                    throw new WakeScheduleException('A one-time wake needs an absolute moment.');
                }
                $runAt = $a['run_at'] instanceof Carbon ? $a['run_at'] : Carbon::parse($a['run_at']);
                if ($runAt->isPast()) {
                    throw new WakeScheduleException('That moment is already in the past.');
                }
                break;

            case WakeSchedule::TYPE_INTERVAL:
                if (($a['interval_seconds'] ?? 0) < 1) {
                    throw new WakeScheduleException('An interval wake needs a positive interval.');
                }
                break;

            case WakeSchedule::TYPE_DAILY:
                $d = $a['daily_seconds'] ?? null;
                if ($d === null || $d < 0 || $d > 86399) {
                    throw new WakeScheduleException('A daily wake needs a valid time of day.');
                }
                break;

            case WakeSchedule::TYPE_CRON:
                if (empty($a['cron_expression']) || !CronExpression::isValidExpression($a['cron_expression'])) {
                    throw new WakeScheduleException('Invalid cron expression.');
                }
                break;

            default:
                throw new WakeScheduleException("Unknown schedule type: '{$type}'.");
        }
    }

    /**
     * Human/agent-readable "when" for the schedule block. Recurring/daily
     * entries render in pulses when the preset uses subjective time.
     */
    protected function describeWhen(WakeSchedule $s, bool $pulsesEnabled): string
    {
        return match ($s->schedule_type) {
            WakeSchedule::TYPE_ONCE =>
                'once ' . optional($s->next_run_at)->format('d.m H:i'),

            WakeSchedule::TYPE_INTERVAL => $pulsesEnabled
                ? 'every ' . $this->pulse->secondsToPulses((int) $s->interval_seconds) . 'p'
                : 'every ' . $this->humanizeSeconds((int) $s->interval_seconds),

            WakeSchedule::TYPE_DAILY => $pulsesEnabled
                ? 'daily p' . $this->pulse->currentPulse(
                    (clone Carbon::now())->startOfDay()->addSeconds((int) $s->daily_seconds)
                )
                : 'daily ' . $this->secondsToClock((int) $s->daily_seconds),

            WakeSchedule::TYPE_CRON => 'cron ' . $s->cron_expression,

            default => '?',
        };
    }

    protected function secondsToClock(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        return sprintf('%02d:%02d', $h, $m);
    }

    protected function humanizeSeconds(int $sec): string
    {
        if ($sec % 3600 === 0) {
            return ($sec / 3600) . 'h';
        }
        if ($sec % 60 === 0) {
            return ($sec / 60) . 'm';
        }
        return $sec . 's';
    }

    protected function truncate(string $s, int $len): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) <= $len ? $s : mb_substr($s, 0, $len - 1) . '…';
    }
}
