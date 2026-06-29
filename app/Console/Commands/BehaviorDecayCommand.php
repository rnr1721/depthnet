<?php

namespace App\Console\Commands;

use App\Contracts\Agent\Behavior\BehaviorDecayRunnerInterface;
use Illuminate\Console\Command;

/**
 * behavior:decay — drive ABS inactivity decay across presets.
 *
 * Cadence is set by the scheduler (Kernel), not here — this command does one
 * pass and reports. Mirrors `contract:tick`.
 *
 * TUNING NOTE (decay ↔ quota coupling): immune patterns are NOT exempt from
 * decay. What keeps an immune pattern alive is its forced-activation quota, which
 * resets its last_activation_seq and pulls it back above the decay floor. So the
 * quota interval must be short enough that decay can't erode the immune pattern
 * to irrelevance between forced activations. These are two independently-set
 * knobs (forced_activation_interval per pattern; factor/idle-grace here) — the
 * relationship is real but intentionally NOT auto-enforced: forcing it would hide
 * the mechanism. Watch the immune pattern's fitness across a few decay runs; if
 * it sinks, shorten its quota or soften the factor.
 */
class BehaviorDecayCommand extends Command
{
    protected $signature = 'behavior:decay
        {--preset= : Decay only this preset id (debugging)}
        {--factor=0.98 : Multiplicative fitness decay applied to idle patterns}
        {--idle-grace=3 : Cycles of inactivity before decay applies}';

    protected $description = 'Apply ABS inactivity decay to behavior patterns.';

    public function handle(BehaviorDecayRunnerInterface $runner): int
    {
        $factor    = (float) $this->option('factor');
        $idleGrace = (int) $this->option('idle-grace');

        if ($factor <= 0.0 || $factor > 1.0) {
            $this->error('factor must be in (0, 1].');
            return self::FAILURE;
        }

        $presetOpt = $this->option('preset');

        if ($presetOpt !== null) {
            $row = $runner->decayPreset((int) $presetOpt, $factor, $idleGrace);
            $rows = $row !== null ? [$row] : [];
        } else {
            $rows = $runner->decayAll($factor, $idleGrace);
        }

        if (empty($rows)) {
            $this->info('No ABS-enabled presets to decay.');
            return self::SUCCESS;
        }

        $this->table(
            ['Preset ID', 'Preset', 'Outcome', 'Detail'],
            array_map(fn ($r) => [$r['preset_id'], $r['preset'], $r['outcome'], $r['detail']], $rows),
        );

        return self::SUCCESS;
    }
}
