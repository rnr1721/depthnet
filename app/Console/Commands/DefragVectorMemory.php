<?php

namespace App\Console\Commands;

use App\Models\AiPreset;
use App\Services\Agent\VectorMemory\DefragService;
use Illuminate\Console\Command;

/**
 * Defragments vector memory for one or all eligible presets.
 *
 * Usage:
 *   php artisan agent:defrag               # all presets with defrag_enabled = true
 *   php artisan agent:defrag --preset=3    # specific preset by ID (ignores defrag_enabled flag)
 */
class DefragVectorMemory extends Command
{
    protected $signature = 'agent:defrag
                            {--preset= : Preset ID to defrag (optional, overrides defrag_enabled flag)}';

    protected $description = 'Defragment vector memory: compress daily records into distilled summaries';

    public function __construct(
        protected DefragService $defragService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $presetId = $this->option('preset');

        $presets = $presetId
            ? AiPreset::where('id', $presetId)->get()
            : AiPreset::where('defrag_enabled', true)->get();

        if ($presets->isEmpty()) {
            $this->warn('No presets found for defrag.');
            return self::SUCCESS;
        }

        foreach ($presets as $preset) {
            $this->info("Defragging preset [{$preset->id}] {$preset->getName()} ...");

            try {
                $result = $this->defragService->defrag($preset);

                $this->table(
                    ['Days processed', 'Records before', 'Records after', 'Removed'],
                    [[
                        $result['days_processed'],
                        $result['records_before'],
                        $result['records_after'],
                        $result['records_removed'],
                    ]]
                );
            } catch (\Throwable $e) {
                $this->error("Failed: " . $e->getMessage());
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
