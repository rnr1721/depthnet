<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\VectorMemory\DefragServiceInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
use App\Services\Agent\DTO\ModelRequestDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Defragments vector memory for a preset.
 *
 * Groups raw memory records by calendar day (oldest first), sends each
 * day's content to the model via the preset's own engine (generate()),
 * persists the distilled records with the original date, and removes
 * the originals.
 *
 * Scope: operates ONLY on the default domain (VectorMemory::DEFAULT_DOMAIN,
 * normally "global"). All other domains are agent-managed namespaces —
 * cold accumulators that the agent curates explicitly. The default domain
 * is the hot, distillable layer where unstructured kristallisations land
 * and need periodic compression.
 *
 * Injects the defrag prompt via additionalParams['system_prompt_override'],
 * which AiModelPromptTrait reads instead of the preset's active prompt.
 * No changes required to providers or the request interface.
 *
 * Expected model response format (JSON array of strings):
 *   ["distilled memory 1", "distilled memory 2", "distilled memory 3"]
 */
class DefragService implements DefragServiceInterface
{
    private const DEFAULT_PROMPT_PATH = 'data/defrag/default_prompt.txt';

    private const TIME_MAP = [
        'morning'     => 8,
        'afternoon'   => 14,
        'evening'     => 19,
        'night'       => 22,
        'late night'  => 23,
    ];

    public function __construct(
        protected PresetRegistryInterface            $presetRegistry,
        protected VectorMemory                       $vectorMemoryModel,
        protected LoggerInterface                    $logger,
        protected MemoryServiceInterface             $memoryService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function defrag(AiPreset $preset): array
    {
        $lockKey = "defrag_lock:{$preset->id}";
        $lockTtl = 300; // 5 minutes — maximum time for the preset

        // Try to acquire the lock. If already taken — skip.
        if (!cache()->add($lockKey, true, $lockTtl)) {
            $this->logger->warning('DefragService: preset already being defragged, skipping', [
                'preset_id' => $preset->id,
            ]);
            return [
                'days_processed'  => 0,
                'records_before'  => 0,
                'records_after'   => 0,
                'records_removed' => 0,
            ];
        }

        try {
            $engine     = $this->presetRegistry->createInstance($preset->getId());
            $keepPerDay = $preset->getDefragKeepPerDay();
            $prompt     = $this->resolvePrompt($preset, $keepPerDay);
            $timezone   = config('app.timezone', 'UTC');

            $recordsBefore = $this->vectorMemoryModel
                ->where('preset_id', $preset->id)
                ->where('domain', VectorMemory::DEFAULT_DOMAIN)
                ->count();

            $days = $this->vectorMemoryModel
                ->where('preset_id', $preset->id)
                ->where('domain', VectorMemory::DEFAULT_DOMAIN)
                ->selectRaw("DATE(CONVERT_TZ(created_at, 'UTC', ?)) as day, COUNT(*) as cnt", [$timezone])
                ->groupBy('day')
                ->havingRaw('cnt > ?', [$keepPerDay])
                ->orderBy('day', 'asc')
                ->pluck('cnt', 'day');

            $daysProcessed = 0;

            foreach ($days as $day => $count) {
                try {
                    $this->defragDay($engine, $preset, $day, $prompt, $keepPerDay, $timezone);
                    $daysProcessed++;
                } catch (\Throwable $e) {
                    $this->logger->error('DefragService: failed to defrag day', [
                        'preset_id' => $preset->id,
                        'day'       => $day,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            $recordsAfter = $this->vectorMemoryModel
                ->where('preset_id', $preset->id)
                ->where('domain', VectorMemory::DEFAULT_DOMAIN)
                ->count();

            $result = [
                'days_processed'  => $daysProcessed,
                'records_before'  => $recordsBefore,
                'records_after'   => $recordsAfter,
                'records_removed' => $recordsBefore - $recordsAfter,
            ];

            $this->logger->info('DefragService: defrag completed', array_merge(
                ['preset_id' => $preset->id, 'preset_name' => $preset->getName()],
                $result
            ));

            return $result;
        } finally {
            // We remove the lock in any case
            cache()->forget($lockKey);
        }
    }

    /**
     * @inheritDoc
     */
    public function getDefaultPrompt(): string
    {
        return $this->loadDefaultPrompt();
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Defragment a single calendar day.
     */
    private function defragDay(
        object   $engine,
        AiPreset $preset,
        string   $day,
        string   $prompt,
        int      $keepPerDay,
        string   $timezone,
    ): void {
        DB::transaction(function () use ($engine, $preset, $day, $prompt, $keepPerDay, $timezone) {
            $memories = $this->vectorMemoryModel
                ->where('preset_id', $preset->id)
                ->where('domain', VectorMemory::DEFAULT_DOMAIN)
                ->whereRaw("DATE(CONVERT_TZ(created_at, 'UTC', ?)) = ?", [$timezone, $day])
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->get();

            if ($memories->isEmpty()) {
                return;
            }

            $content = $this->buildDayContent($memories, $day);

            $request = new ModelRequestDTO(
                preset:                    $preset,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   [['role' => 'user', 'content' => $content]],
                additionalParams:          ['system_prompt_override' => $prompt],
            );

            $response = $engine->generate($request);

            if ($response->isError()) {
                throw new \RuntimeException('Engine returned error: ' . $response->getResponse());
            }

            $distilled = $this->parseResponse($response->getResponse(), $keepPerDay);

            if (empty($distilled)) {
                $this->logger->warning('DefragService: empty distilled result, skipping day', [
                    'preset_id' => $preset->id,
                    'day'       => $day,
                ]);
                return;
            }

            foreach ($distilled as $text) {
                $text = trim($text);
                if (empty($text)) {
                    continue;
                }

                $timestamp = $this->resolveTimestamp($day, $text, $timezone);

                $memory = $this->vectorMemoryModel->newInstance([
                    'preset_id'    => $preset->id,
                    'domain'       => VectorMemory::DEFAULT_DOMAIN,
                    'content'      => $text,
                    'tfidf_vector' => [],
                    'keywords'     => [],
                    'importance'   => 1.0,
                ]);

                $memory->withoutTimestamps(function () use ($memory, $timestamp) {
                    $memory->created_at = $timestamp;
                    $memory->updated_at = $timestamp;
                    $memory->save();
                });
            }

            $this->vectorMemoryModel->whereIn('id', $memories->pluck('id')->toArray())->delete();

            $this->logger->info('DefragService: day defragged', [
                'preset_id' => $preset->id,
                'day'       => $day,
                'before'    => $memories->count(),
                'after'     => count($distilled),
            ]);
        });
    }

    /**
     * Build numbered list of memory entries for one day.
     */
    private function buildDayContent(Collection $memories, string $day): string
    {
        $header = "=== {$day} ===\n";

        // Build strings and truncate if too many
        $maxEntries = 50; // Approximate limit

        $subset = $memories->values()->slice(-$maxEntries); // Take the last N

        $body = $subset->map(function (VectorMemory $m, int $i) use ($subset) {
            // The numbering is original; if it has been cut off, we add a note.
            return ($i + 1) . '. ' . trim($m->content);
        })->implode("\n");

        if ($memories->count() > $maxEntries) {
            $body = "[... " . ($memories->count() - $maxEntries) . " earlier entries omitted ...]\n" . $body;
        }

        return $header . $body;
    }

    /**
     * Parse JSON array from model response.
     * Strips markdown code fences if present.
     */
    private function parseResponse(string $raw, int $keepPerDay): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'DefragService: could not parse model response as JSON array. Raw: ' . substr($raw, 0, 300)
            );
        }

        $strings = array_values(array_filter($decoded, 'is_string'));

        return array_slice($strings, 0, $keepPerDay);
    }

    /**
     * Resolve effective prompt: custom from preset or default from file.
     * Replaces [[keep]] placeholder with actual value.
     */
    private function resolvePrompt(AiPreset $preset, int $keepPerDay): string
    {
        $custom = trim($preset->getDefragPrompt() ?? '');

        $prompt = !empty($custom)
            ? $custom
            : $this->loadDefaultPrompt();

        return str_replace('[[keep]]', (string) $keepPerDay, $prompt);
    }

    /**
     * Load default prompt from file, stripping comment lines.
     *
     * @throws \RuntimeException
     */
    private function loadDefaultPrompt(): string
    {
        $path = base_path(self::DEFAULT_PROMPT_PATH);

        if (!file_exists($path)) {
            throw new \RuntimeException(
                'DefragService: default prompt file not found at ' . $path
            );
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(
                'DefragService: could not read default prompt file at ' . $path
            );
        }

        $lines = array_filter(
            explode("\n", $content),
            fn (string $line) => !str_starts_with(trim($line), '#')
        );

        return trim(implode("\n", $lines));
    }

    /**
     * Extract time-of-day marker from distilled memory text and resolve to Carbon timestamp.
     * Expects the memory to start with "Morning:", "Afternoon:", "Evening:", "Night:", or "Late night:".
     * Falls back to noon (12:00) if no known marker is found.
     */
    private function resolveTimestamp(string $day, string $text, string $timezone): Carbon
    {
        $hour = 12;

        if (preg_match('/^(morning|afternoon|evening|night|late\s*night)/i', $text, $matches)) {
            $marker = strtolower(trim($matches[1]));
            $hour = self::TIME_MAP[$marker] ?? 12;
        }

        $minute = abs(crc32($text) % 29); // 0 to 28 minutes

        return Carbon::createFromFormat('Y-m-d', $day, $timezone)
            ->setTime($hour, $minute, 0)
            ->utc();
    }

}
