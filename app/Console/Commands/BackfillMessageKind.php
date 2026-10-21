<?php

namespace App\Console\Commands;

use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Backfills metadata.kind on user-role messages written before kind marking
 * existed, so the episode-aware context window can see old episode boundaries.
 *
 * Heuristic and conservative by design: a row it is unsure about is left
 * unmarked, which reads as KIND_INPUT — an extra episode boundary, never a
 * missing one. Re-runnable: rows that already carry a kind are skipped.
 *
 * Rules, first match wins:
 *   1. source = compaction                              → recap
 *   2. content is "Continue" / "Continue." or one of the
 *      configured cycle start/continue instructions     → continue
 *   3. no source, pool JSON whose every source name is
 *      synthetic ("system", the preset's own name,
 *      the cycle-prompt preset's name)                  → continue
 *   4. no source, plain text — only with
 *      --unmarked-plain-as-continue (plain-mode cycle
 *      prompts are unmarked; so may be inputs from
 *      integrations that never set a source)            → continue
 *   otherwise left unmarked (= input)
 */
class BackfillMessageKind extends Command
{
    protected $signature = 'depthnet:backfill-message-kind
        {--preset= : Only this preset id}
        {--dry-run : Report what would change without writing}
        {--unmarked-plain-as-continue : Treat unmarked plain-text user rows as continuations}';

    protected $description = 'Backfill metadata.kind (input/continue/recap) on historical user messages';

    public function handle(OptionsServiceInterface $options): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $plainRule = (bool) $this->option('unmarked-plain-as-continue');

        $staticContinue = array_values(array_filter(array_map('trim', [
            'Continue',
            'Continue.',
            (string) $options->get('agent_cycle_start_instruction', '[Start your first thinking cycle]'),
            (string) $options->get('agent_cycle_continue_instruction', '[Continue your thinking cycle]'),
        ])));

        $presets = AiPreset::query()
            ->when($this->option('preset'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        $totals = [Message::KIND_RECAP => 0, Message::KIND_CONTINUE => 0, 'left_as_input' => 0];

        foreach ($presets as $preset) {
            $syntheticSources = $this->syntheticSourceNames($preset);

            Message::query()
                ->forPreset($preset->getId())
                ->where('role', 'user')
                ->chunkById(500, function ($rows) use ($staticContinue, $syntheticSources, $plainRule, $dryRun, &$totals) {
                    foreach ($rows as $row) {
                        $meta = $row->metadata ?? [];
                        if (isset($meta['kind'])) {
                            continue;
                        }

                        $kind = $this->classify($row, $meta, $staticContinue, $syntheticSources, $plainRule);
                        if ($kind === null) {
                            $totals['left_as_input']++;
                            continue;
                        }

                        $totals[$kind]++;
                        if (!$dryRun) {
                            $meta['kind'] = $kind;
                            $row->metadata = $meta;
                            $row->saveQuietly();
                        }
                    }
                });
        }

        $this->table(['recap', 'continue', 'left as input'], [[
            $totals[Message::KIND_RECAP],
            $totals[Message::KIND_CONTINUE],
            $totals['left_as_input'],
        ]]);

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    private function classify(
        Message $row,
        array $meta,
        array $staticContinue,
        array $syntheticSources,
        bool $plainRule
    ): ?string {
        $source  = $meta['source'] ?? null;
        $content = trim((string) $row->content);

        if ($source === Message::SOURCE_COMPACTION) {
            return Message::KIND_RECAP;
        }

        if (in_array($content, $staticContinue, true)) {
            return Message::KIND_CONTINUE;
        }

        if ($source !== null) {
            return null; // a marked entry point (web/api/agent/...) — real input
        }

        $pool = json_decode($content, true);
        if (is_array($pool) && isset($pool['sources']) && is_array($pool['sources'])) {
            if ($pool['sources'] === []) {
                return null;
            }
            foreach ($pool['sources'] as $item) {
                if (!in_array((string) ($item['source'] ?? ''), $syntheticSources, true)) {
                    return null; // at least one real source in the payload
                }
            }
            return Message::KIND_CONTINUE;
        }

        return $plainRule ? Message::KIND_CONTINUE : null;
    }

    /**
     * Source names the system itself uses when it writes into a preset's pool.
     *
     * @return string[]
     */
    private function syntheticSourceNames(AiPreset $preset): array
    {
        $names = ['system', $preset->getName()];

        if ($preset->hasCyclePrompt() && $preset->cyclePromptPreset) {
            $names[] = $preset->cyclePromptPreset->getName();
        }

        return array_values(array_unique($names));
    }
}
