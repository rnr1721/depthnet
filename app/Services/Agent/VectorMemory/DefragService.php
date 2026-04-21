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
        $engine     = $this->presetRegistry->createInstance($preset->getId());
        $keepPerDay = $preset->getDefragKeepPerDay();
        $prompt     = $this->resolvePrompt($preset, $keepPerDay);
        $timezone   = config('app.timezone', 'UTC');

        $recordsBefore = $this->vectorMemoryModel->where('preset_id', $preset->id)->count();

        $days = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
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

        $recordsAfter = $this->vectorMemoryModel->where('preset_id', $preset->id)->count();

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
        $memories = $this->vectorMemoryModel
            ->where('preset_id', $preset->id)
            ->whereRaw("DATE(CONVERT_TZ(created_at, 'UTC', ?)) = ?", [$timezone, $day])
            ->orderBy('created_at', 'asc')
            ->get();

        if ($memories->isEmpty()) {
            return;
        }

        $content = $this->buildDayContent($memories);

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

        $dayTimestamp = Carbon::createFromFormat('Y-m-d', $day, $timezone)
            ->setTime(12, 0, 0)
            ->utc();

        $originalIds = $memories->pluck('id')->toArray();

        DB::transaction(function () use ($preset, $distilled, $dayTimestamp, $originalIds) {
            foreach ($distilled as $text) {
                $text = trim($text);
                if (empty($text)) {
                    continue;
                }

                $memory = $this->vectorMemoryModel->newInstance([
                    'preset_id'    => $preset->id,
                    'content'      => $text,
                    'tfidf_vector' => [],
                    'keywords'     => [],
                    'importance'   => 1.0,
                ]);

                $memory->withoutTimestamps(function () use ($memory, $dayTimestamp) {
                    $memory->created_at = $dayTimestamp;
                    $memory->updated_at = $dayTimestamp;
                    $memory->save();
                });
            }

            $this->vectorMemoryModel->whereIn('id', $originalIds)->delete();
        });

        $this->logger->info('DefragService: day defragged', [
            'preset_id' => $preset->id,
            'day'       => $day,
            'before'    => count($originalIds),
            'after'     => count($distilled),
        ]);
    }

    /**
     * Build numbered list of memory entries for one day.
     */
    private function buildDayContent(\Illuminate\Support\Collection $memories): string
    {
        return $memories->values()->map(function (VectorMemory $m, int $i) {
            return ($i + 1) . '. ' . trim($m->content);
        })->implode("\n");
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
}
