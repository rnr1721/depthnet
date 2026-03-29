<?php

namespace App\Console\Commands;

use App\Contracts\Agent\Capabilities\EmbeddingServiceInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Models\AiPreset;
use App\Models\JournalEntry;
use App\Models\PersonMemory;
use App\Models\VectorMemory;
use App\Services\Agent\VectorMemory\EmbeddingVectorMemoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Migrates existing vector memory records to dense embeddings.
 *
 * Records without an embedding vector continue to work via TF-IDF fallback,
 * so this migration is safe to run incrementally while the system is live.
 *
 * Usage:
 *   php artisan vectormemory:embed --preset=1
 *   php artisan vectormemory:embed --all
 *   php artisan vectormemory:embed --all --batch=25 --sleep=2000
 *   php artisan vectormemory:embed --preset=1 --dry-run
 */
class EmbedVectorMemoryCommand extends Command
{
    protected $signature = 'vectormemory:embed
        {--preset=   : Preset ID to migrate}
        {--all       : Migrate all presets that have an embedding capability configured}
        {--journal   : Also backfill journal entries (in addition to vector memories)}
        {--persons   : Also backfill person memory facts (in addition to vector memories)}
        {--batch=50  : Records per API batch (default: 50)}
        {--sleep=1000: Milliseconds to sleep between batches (default: 1000)}
        {--dry-run   : Show what would be migrated without making API calls}';

    protected $description = 'Backfill dense embedding vectors for vector memories, journal entries and/or person facts.';

    public function __construct(
        protected EmbeddingVectorMemoryService $vectorMemoryService,
        protected EmbeddingServiceInterface    $embeddingService,
        protected PresetServiceInterface       $presetService,
        protected JournalEntry                 $journalModel,
        protected PersonMemory                 $personModel,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $presets = $this->resolvePresets();

        if ($presets->isEmpty()) {
            $this->error('No presets found. Use --preset=ID or --all.');
            return self::FAILURE;
        }

        $dryRun    = $this->option('dry-run');
        $batchSize = max(1, min(100, (int) $this->option('batch')));
        $sleepMs   = max(0, (int) $this->option('sleep'));

        if ($dryRun) {
            $this->warn('DRY RUN — no API calls will be made.');
        }

        $totalProcessed = 0;
        $totalFailed    = 0;

        foreach ($presets as $preset) {
            [$processed, $failed] = $this->migratePreset(
                $preset,
                $batchSize,
                $sleepMs,
                $dryRun
            );
            $totalProcessed += $processed;
            $totalFailed    += $failed;

            if ($this->option('journal')) {
                [$jp, $jf] = $this->migrateJournal($preset, $batchSize, $sleepMs, $dryRun);
                $totalProcessed += $jp;
                $totalFailed    += $jf;
            }

            if ($this->option('persons')) {
                [$pp, $pf] = $this->migratePersons($preset, $batchSize, $sleepMs, $dryRun);
                $totalProcessed += $pp;
                $totalFailed    += $pf;
            }
        }

        $this->newLine();
        $this->info("Done. Processed: {$totalProcessed}, Failed: {$totalFailed}.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Per-preset migration ──────────────────────────────────────────────────

    private function migratePreset(
        AiPreset $preset,
        int      $batchSize,
        int      $sleepMs,
        bool     $dryRun,
    ): array {
        $total = VectorMemory::where('preset_id', $preset->id)
            ->whereNull('embedding')
            ->count();

        if ($total === 0) {
            $this->line("<info>[{$preset->getName()}]</info> All records already have embeddings. Skipping.");
            return [0, 0];
        }

        $this->newLine();
        $this->line("<info>[{$preset->getName()}]</info> {$total} records need embedding.");

        if ($dryRun) {
            $this->line("  Would process {$total} records in batches of {$batchSize}.");
            return [0, 0];
        }

        // Check embedding capability before starting
        if (!$this->embeddingService->isAvailable($preset)) {
            $this->error("  No active embedding capability configured for preset '{$preset->getName()}'. Skipping.");
            $this->line("  → Configure it at: Admin → Capabilities → Embedding");
            return [0, 0];
        }

        $bar       = $this->output->createProgressBar($total);
        $processed = 0;
        $failed    = 0;

        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — processed: %processed% failed: %failed%");
        $bar->setMessage('0', 'processed');
        $bar->setMessage('0', 'failed');
        $bar->start();

        do {
            $result = $this->vectorMemoryService->backfillEmbeddings($preset, $batchSize);

            $processed += $result['processed'];
            $failed    += $result['failed'];

            $bar->setMessage((string) $processed, 'processed');
            $bar->setMessage((string) $failed, 'failed');
            $bar->advance($result['processed'] + $result['failed']);

            if ($result['remaining'] > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

        } while ($result['remaining'] > 0);

        $bar->finish();
        $this->newLine();

        if ($failed > 0) {
            $this->warn("  Completed with {$failed} failures. Re-run to retry failed records.");
        }

        return [$processed, $failed];
    }


    // ── Journal migration ─────────────────────────────────────────────────────

    private function migrateJournal(
        AiPreset $preset,
        int      $batchSize,
        int      $sleepMs,
        bool     $dryRun,
    ): array {
        $total = JournalEntry::where('preset_id', $preset->id)
            ->whereNull('embedding')
            ->count();

        $name = $preset->getName();

        if ($total === 0) {
            $this->line("<info>[{$name} / journal]</info> All entries already have embeddings.");
            return [0, 0];
        }

        $this->line("<info>[{$name} / journal]</info> {$total} entries need embedding.");

        if ($dryRun) {
            $this->line("  Would process {$total} entries in batches of {$batchSize}.");
            return [0, 0];
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — processed: %processed% failed: %failed%");
        $bar->setMessage('0', 'processed');
        $bar->setMessage('0', 'failed');
        $bar->start();

        $processed = 0;
        $failed    = 0;
        $remaining = $total;

        do {
            $entries = JournalEntry::where('preset_id', $preset->id)
                ->whereNull('embedding')
                ->limit($batchSize)
                ->get();

            if ($entries->isEmpty()) {
                break;
            }

            foreach ($entries as $entry) {
                $text   = $entry->summary . ($entry->details ? ' ' . $entry->details : '');
                $vector = $this->embeddingService->embed($text, $preset);

                if ($vector !== null) {
                    $entry->update(['embedding' => $vector, 'embedding_dim' => count($vector)]);
                    $processed++;
                } else {
                    $failed++;
                }
            }

            $bar->setMessage((string) $processed, 'processed');
            $bar->setMessage((string) $failed, 'failed');
            $bar->advance($entries->count());

            $remaining = JournalEntry::where('preset_id', $preset->id)->whereNull('embedding')->count();

            if ($remaining > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

        } while ($remaining > 0);

        $bar->finish();
        $this->newLine();

        return [$processed, $failed];
    }

    // ── Person facts migration ────────────────────────────────────────────────

    private function migratePersons(
        AiPreset $preset,
        int      $batchSize,
        int      $sleepMs,
        bool     $dryRun,
    ): array {
        $total = PersonMemory::where('preset_id', $preset->id)
            ->whereNull('embedding')
            ->count();

        $name = $preset->getName();

        if ($total === 0) {
            $this->line("<info>[{$name} / persons]</info> All facts already have embeddings.");
            return [0, 0];
        }

        $this->line("<info>[{$name} / persons]</info> {$total} facts need embedding.");

        if ($dryRun) {
            $this->line("  Would process {$total} facts in batches of {$batchSize}.");
            return [0, 0];
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — processed: %processed% failed: %failed%");
        $bar->setMessage('0', 'processed');
        $bar->setMessage('0', 'failed');
        $bar->start();

        $processed = 0;
        $failed    = 0;

        do {
            $facts = PersonMemory::where('preset_id', $preset->id)
                ->whereNull('embedding')
                ->limit($batchSize)
                ->get();

            if ($facts->isEmpty()) {
                break;
            }

            foreach ($facts as $fact) {
                $vector = $this->embeddingService->embed($fact->content, $preset);

                if ($vector !== null) {
                    $fact->update(['embedding' => $vector, 'embedding_dim' => count($vector)]);
                    $processed++;
                } else {
                    $failed++;
                }
            }

            $bar->setMessage((string) $processed, 'processed');
            $bar->setMessage((string) $failed, 'failed');
            $bar->advance($facts->count());

            $remaining = PersonMemory::where('preset_id', $preset->id)->whereNull('embedding')->count();

            if ($remaining > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

        } while ($remaining > 0);

        $bar->finish();
        $this->newLine();

        if ($failed > 0) {
            $this->warn("  Completed with {$failed} failures. Re-run to retry failed facts.");
        }

        return [$processed, $failed];
    }

    // ── Preset resolution ─────────────────────────────────────────────────────

    private function resolvePresets(): Collection
    {
        // Single preset by ID
        if ($presetId = $this->option('preset')) {
            $preset = $this->presetService->findById((int) $presetId);

            if (!$preset) {
                $this->error("Preset #{$presetId} not found.");
                return collect();
            }

            return collect([$preset]);
        }

        // All presets
        if ($this->option('all')) {
            $presets = AiPreset::where('is_active', true)->get();

            if ($presets->isEmpty()) {
                $this->warn('No active presets found.');
                return collect();
            }

            // Filter to those with embedding capability configured
            $withCapability = $presets->filter(
                fn ($p) => $this->embeddingService->isAvailable($p)
            );

            $skipped = $presets->count() - $withCapability->count();

            if ($skipped > 0) {
                $this->warn("{$skipped} preset(s) skipped — no embedding capability configured.");
            }

            if ($withCapability->isEmpty()) {
                $this->error('No presets have an embedding capability configured.');
                $this->line('→ Configure one at: Admin → Capabilities → Embedding');
                return collect();
            }

            return $withCapability->values();
        }

        return collect();
    }
}
