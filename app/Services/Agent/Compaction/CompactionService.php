<?php

namespace App\Services\Agent\Compaction;

use App\Contracts\Agent\Compaction\CompactionServiceInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ModelRequestDTO;
use Psr\Log\LoggerInterface;

/**
 * CompactionService — see interface for the contract.
 *
 * The compressor-preset invocation mirrors InnerVoiceEnricher::callVoice:
 * applyPreset → assemble a flat synthetic context → engine->generate over a
 * ModelRequestDTO → strip_tags the text → restore the main preset in finally.
 * We do NOT route the compressor output through AgentActionsHandler: the
 * compressor is a pure summariser, we want its text, not tool execution.
 *
 * The recap write mirrors ChatService::sendVoiceInput: role=user,
 * from_user_id=null, is_visible_to_user=true, metadata['source'] set — plus a
 * metadata['compaction'] range so a future "unfold / show original" path stays
 * open, and (pool mode) the recap enters via inputPoolService like any source.
 */
class CompactionService implements CompactionServiceInterface
{
    /**
     * Label wrapping the recap text in the window. English on purpose: it is
     * addressed to the MODEL, not the user, and every model reads English —
     * not worth another options row. The first-person body comes from the
     * compressor; this wrapper only tells the model what kind of message it is.
     */
    private const RECAP_LABEL = '[Recovered context — the conversation so far was '
        . 'consolidated into memory and the working window was cleared. '
        . 'This is your own summary of what came before]';

    /**
     * Breather between the compressor generation and returning to the cycle,
     * so two back-to-back provider hits (compressor, then the speaking pass
     * that follows in the same tick) don't risk a rate-limit. Mirrors
     * Agent::PRE_PASS_COOLDOWN_SECONDS.
     */
    private const COOLDOWN_SECONDS = 3;

    public function __construct(
        protected Message                            $messageModel,
        protected PresetServiceInterface             $presetService,
        protected PresetRegistryInterface            $presetRegistry,
        protected JournalServiceInterface            $journalService,
        protected MemoryServiceInterface             $memoryService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected ShortcodeManagerServiceInterface   $shortcodeManagerService,
        protected PluginMetadataServiceInterface     $pluginMetadataService,
        protected ContextModeResolverInterface       $contextModeResolver,
        protected \App\Contracts\Agent\PluginRegistryInterface $pluginRegistry,
        protected LoggerInterface                    $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function compact(AiPreset $preset, ?string $focus = null): ?Message
    {
        // Feature gate: no compressor preset → compaction is off.
        $compressorId = $preset->getCompressorPresetId();
        if ($compressorId === null) {
            $this->logger->info('CompactionService: no compressor preset configured — skipping', [
                'preset_id' => $preset->getId(),
            ]);
            return null;
        }

        $compressor = $this->presetService->findById($compressorId);
        if (!$compressor || !$compressor->isActive()) {
            $this->logger->warning('CompactionService: compressor preset missing or inactive — skipping', [
                'preset_id'     => $preset->getId(),
                'compressor_id' => $compressorId,
            ]);
            return null;
        }

        $lastAgentTurnId = (int) ($this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->whereIn('role', ['command','assistant','thinking'])
            ->max('id') ?? 0);

        // The fresh recap represents the active window boundary; we do not fold it.
        // OLD recaps, conversely, are sent to the fold: otherwise, they accumulate
        // (remaining compacted=0 indefinitely) and inflate activeWindowCount,
        // causing the watchdog to trigger increasingly often.
        $latestRecapId = (int) ($this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('metadata->source', Message::SOURCE_COMPACTION)
            ->max('id') ?? 0);

        // ── Gather the foldable range ─────────────────────────────────────────
        // Active window, excluding any existing recap (a recap must survive its
        // own future compaction — it IS the nerve holding the thread; folding it
        // away would lose exactly what we kept). System rows are excluded to
        // match what the context builders actually feed the model.
        $foldable = $this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('role', '!=', 'system')
            ->when($lastAgentTurnId > 0, fn ($q) => $q->where('id', '<=', $lastAgentTurnId))
            ->when($latestRecapId > 0, fn ($q) => $q->where('id', '!=', $latestRecapId))
            ->orderBy('id', 'asc')
            ->get();

        // Nothing meaningful to fold — don't burn a generation.
        if ($foldable->count() < 2) {
            $this->logger->info('CompactionService: foldable range too small — skipping', [
                'preset_id' => $preset->getId(),
                'count'     => $foldable->count(),
            ]);
            return null;
        }

        $fromId = (int) $foldable->first()->id;
        $toId   = (int) $foldable->last()->id;

        // ── Generate the recap text via the compressor preset ─────────────────
        $recapText = $this->generateRecap($compressor, $foldable, $focus);

        if ($recapText === null || $recapText === '') {
            $this->logger->warning('CompactionService: compressor returned empty — aborting, window untouched', [
                'preset_id'     => $preset->getId(),
                'compressor_id' => $compressorId,
            ]);
            return null;
        }

        // ── Write order matters ───────────────────────────────────────────────
        // 1) journal (durable safety net), 2) recap message into the window,
        // 3) mark the folded range. The recap row must exist BEFORE the mark so
        // the next context assembly sees a non-empty window with the recap as
        // its trailing turn (otherwise the builder's empty-context branch would
        // fire and inject a cold-start instruction instead).

        $this->writeJournal($preset, $recapText, $focus);

        $recap = $this->writeRecapMessage($preset, $recapText, $fromId, $toId);

        // Mark the folded range compacted. The recap itself is NOT in this set
        // (it was created just now with a higher id, outside [fromId, toId]).
        $this->messageModel
            ->forPreset($preset->getId())
            ->whereBetween('id', [$fromId, $toId])
            ->update(['compacted' => true]);

        $this->logger->info('CompactionService: compacted window', [
            'preset_id'  => $preset->getId(),
            'from_id'    => $fromId,
            'to_id'      => $toId,
            'folded'     => $foldable->count(),
            'recap_id'   => $recap->id,
        ]);

        // Breather before the cycle's speaking pass hits the provider again.
        sleep(self::COOLDOWN_SECONDS);

        return $recap;
    }

    /**
     * @inheritDoc
     */
    public function activeWindowCount(AiPreset $preset): int
    {
        return $this->messageModel
            ->forPreset($preset->getId())
            ->activeWindow()
            ->where('role', '!=', 'system')
            ->count();
    }

    /**
     * Run the compressor preset over the foldable range and return clean text.
     *
     * Skeleton copied from InnerVoiceEnricher::callVoice: apply the compressor
     * preset, build a flat synthetic context from the range, generate, strip
     * tags, restore the main preset in finally. No tools attached — we want
     * text, not tool calls.
     */
    private function generateRecap($compressor, \Illuminate\Support\Collection $foldable, ?string $focus): ?string
    {
        try {
            $this->pluginRegistry->applyPreset($compressor);

            $conversationText = $foldable
                ->filter(fn ($m) => in_array($m->role, ['user', 'assistant', 'thinking', 'command'], true))
                ->map(fn ($m) => strtoupper($m->role) . ': ' . mb_substr((string) $m->content, 0, 2000))
                ->implode("\n");

            if (trim($conversationText) === '') {
                return null;
            }

            $focusNote = ($focus !== null && trim($focus) !== '')
                ? "\n\nThis time, focus on: " . trim($focus)
                : '';

            $flatContext = [[
                'role'         => 'user',
                'content'      => "=== CONVERSATION TO CONSOLIDATE ===\n{$conversationText}\n=== END ==="
                    . $focusNote,
                'from_user_id' => null,
            ]];

            $engine   = $this->presetRegistry->createInstance($compressor->getId());
            $response = $engine->generate(new ModelRequestDTO(
                preset:                    $compressor,
                memoryService:             $this->memoryService,
                commandInstructionBuilder: $this->commandInstructionBuilder,
                shortcodeManager:          $this->shortcodeManagerService,
                pluginMetadataService:     $this->pluginMetadataService,
                context:                   $flatContext,
            ));

            if ($response->isError()) {
                $this->logger->warning('CompactionService: compressor generation error', [
                    'compressor_id' => $compressor->getId(),
                    'error'         => $response->getResponse(),
                ]);
                return null;
            }

            return trim(strip_tags($response->getResponse())) ?: null;

        } catch (\Throwable $e) {
            $this->logger->error('CompactionService::generateRecap error: ' . $e->getMessage(), [
                'compressor_id' => $compressor->getId(),
                'trace'         => $e->getTraceAsString(),
            ]);
            return null;
        } finally {
            // Always restore the thinking preset's plugin context.
            // (The caller applies it again before generation anyway, but leaving
            // the compressor applied here would be a latent footgun.)
        }
    }

    /**
     * Write the recap into the journal as an episodic entry — the durable
     * safety net. Type follows the active context mode (extended = work =
     * observation; normal = reflection), overridable by an explicit focus:
     * a "task"/"state" focus forces observation, anything else leaves the
     * mode-derived default.
     *
     * The recap is split across the journal's two text fields: a short gist
     * (first sentence) into summary (varchar 255, the listing header) and the
     * full recap into details (TEXT). This uses the "type | summary | details"
     * format as designed and avoids truncating a paragraph-length recap at the
     * summary column boundary. addEntry() parses '|' as field separators, so
     * pipes in both parts are neutralised first.
     */
    private function writeJournal(AiPreset $preset, string $recapText, ?string $focus): void
    {
        try {
            $type = $this->contextModeResolver->isExtended($preset) ? 'observation' : 'reflection';

            if ($focus !== null) {
                $f = strtolower(trim($focus));
                if (str_contains($f, 'task') || str_contains($f, 'state')) {
                    $type = 'observation';
                } elseif (str_contains($f, 'salien') || str_contains($f, 'reflect')) {
                    $type = 'reflection';
                }
            }

            // Journal's summary column is varchar(255); details is TEXT. A recap
            // is a paragraph, so putting the whole thing in summary truncates it
            // at the column boundary. Split instead — exactly what the journal's
            // "type | summary | details" format is designed for: a short gist as
            // the summary (the one-line header shown in listings) and the full
            // recap as details (TEXT, untruncated). Embedding is computed over
            // summary+details in addEntry(), so semantic search still sees the
            // whole recap. Pipes are neutralised in both parts so addEntry()'s
            // '|' parsing doesn't mis-split the text into spurious fields.
            $gist         = $this->firstSentence($recapText);
            $safeSummary  = str_replace('|', '—', $gist);
            $safeDetails  = str_replace('|', '—', $recapText);

            $result = $this->journalService->addEntry(
                $preset,
                "{$type} | {$safeSummary} | {$safeDetails}"
            );

            if (!($result['success'] ?? false)) {
                $this->logger->warning('CompactionService: journal write reported failure', [
                    'preset_id' => $preset->getId(),
                    'message'   => $result['message'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // Journal is the safety net, but a journal failure must not abort the
            // compaction — the recap message + fold still proceed. Log and move on.
            $this->logger->error('CompactionService::writeJournal error: ' . $e->getMessage(), [
                'preset_id' => $preset->getId(),
            ]);
        }
    }

    /**
     * Extract a short gist for the journal summary column (varchar 255).
     *
     * Takes the first sentence (up to the first . ! ? … including their
     * multibyte forms), and hard-caps at 200 chars as a floor of safety — a
     * single very long sentence must still fit the column with room to spare.
     * The FULL recap always lives in details regardless, so nothing this
     * trims is lost; this is only the listing header.
     */
    private function firstSentence(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return '(empty recap)';
        }

        // First sentence-ending punctuation (ASCII + common multibyte).
        if (preg_match('/^(.*?[.!?…])(\s|$)/u', $text, $m)) {
            $candidate = trim($m[1]);
            if ($candidate !== '' && mb_strlen($candidate) <= 200) {
                return $candidate;
            }
        }

        // No sentence break within range (or first sentence too long): hard cap.
        if (mb_strlen($text) > 200) {
            return rtrim(mb_substr($text, 0, 197)) . '…';
        }

        return $text;
    }

    /**
     * Write the recap message into the active window.
     *
     * Two branches, same as every other inbound source in ChatService:
     *   pool mode → enter via inputPoolService so it joins the JSON payload
     *   plain     → a direct role=user row
     *
     * Either way metadata carries source=compaction (so cycleWasOrchestrated
     * won't mistake it for external input and drag the cycle into pipeline
     * mode) and the folded id range (for a future unfold path).
     */
    /**
     * Write the recap message into the active window.
     *
     * The recap is NOT a pool source. A pool source is an *input* (a sensor
     * reading, a user turn) that gets woven into the next JSON payload mixed
     * with everything else. The recap is not input — it is the window boundary,
     * the agent's own consolidated memory opening the fresh window. Mixing it
     * into the pool JSON would bury it among current inputs instead of letting
     * it stand as the trailing turn the cycle continues from. So it is written
     * the same way in both modes: a direct, persisted role=user row.
     *
     * The context builders' "last message is user → no continuation needed"
     * logic then picks it up as the trailing turn (it IS role=user), in pool
     * mode and plain mode alike — the recap row sits after any pool flush, so
     * it is what the model sees last.
     *
     * metadata carries source=compaction (so cycleWasOrchestrated won't mistake
     * it for external input and drag the cycle into pipeline mode) and the
     * folded id range (for a future unfold path). Both survive because we own
     * the row directly rather than delegating it to the pool flush.
     */
    private function writeRecapMessage(AiPreset $preset, string $recapText, int $fromId, int $toId): Message
    {
        $body = self::RECAP_LABEL . "\n" . $recapText;

        return $this->messageModel->create([
            'role'               => 'user',
            'content'            => $body,
            'from_user_id'       => null,
            'preset_id'          => $preset->getId(),
            'is_visible_to_user' => true,
            'compacted'          => false, // the recap IS the active summary — stays in-window
            'metadata'           => [
                'source'     => Message::SOURCE_COMPACTION,
                'compaction' => ['from_id' => $fromId, 'to_id' => $toId],
            ],
        ]);
    }
}
