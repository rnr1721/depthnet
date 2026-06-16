<?php

namespace App\Console\Commands;

use App\Services\Agent\Contract\ContractTickRunner;
use Illuminate\Console\Command;

/**
 * Drives the contract metabolism: ticks every preset whose contract plugin is
 * enabled (or one specific preset).
 *
 * Registered to run every minute via the scheduler (see routes/console.php);
 * the per-tick `tick_min_seconds` coalescing inside the engine is what actually
 * paces evaluation, so running every minute is cheap — most ticks coalesce away.
 *
 * Usage:
 *   php artisan contract:tick               # all eligible presets (the cron path)
 *   php artisan contract:tick --preset=3    # one preset, for hands-on debugging
 */
class ContractTickCommand extends Command
{
    protected $signature = 'contract:tick
                            {--preset= : Preset ID to tick (optional; default ticks all eligible presets)}';

    protected $description = 'Tick the contract metabolism engine for eligible presets';

    public function __construct(
        protected ContractTickRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $presetId = $this->option('preset');

        if ($presetId !== null) {
            $row = $this->runner->tickPreset((int) $presetId);

            if ($row === null) {
                $this->warn("Preset #{$presetId} not found or contract plugin disabled.");
                return self::SUCCESS;
            }

            $rows = [$row];
        } else {
            $rows = $this->runner->tickAll();
        }

        if (empty($rows)) {
            // Quiet on the cron path — nothing eligible is a normal steady state.
            if ($this->option('preset') === null) {
                return self::SUCCESS;
            }
            $this->warn('No eligible presets.');
            return self::SUCCESS;
        }

        $this->table(
            ['Preset', 'Name', 'Outcome', 'Detail'],
            array_map(fn (array $r) => [
                $r['preset_id'],
                $r['preset'],
                $r['outcome'],
                $r['detail'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
