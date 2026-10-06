<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\Skills\SkillLoadService;
use Psr\Log\LoggerInterface;

/**
 * ContextInjectionService — the post-assembly injection layer.
 *
 * After a context builder has FULLY assembled the cycle context (history window,
 * recap lift, RAG placeholders, trailing-turn fixup — everything), this service
 * prepends ephemeral "desktop" material the MODEL needs but no other subsystem
 * does. Called as the last step before the builder returns, so it has zero effect
 * on RAG query formulation, compaction, or the recap logic — all of which have
 * already run over the context WITHOUT this material.
 *
 * ── Why "desktop", not "identity" ────────────────────────────────────────────
 * Desktop material is what the agent is holding right now — data for the task,
 * not part of who the agent IS. That is why it goes into the HISTORY (the work
 * stream) as the oldest messages, not into the system prompt (which is identity:
 * character, reasoning, behavior, intentions such as [[active_goals]]).
 * Things on the desk appear and leave; the identity prompt should not flicker.
 *
 * ── Consumers, in reading order ──────────────────────────────────────────────
 *   1. Goal in focus  — WHAT I am doing and why, and how far I got (full progress
 *                       history of the single focused goal).
 *   2. Loaded skills  — HOW: the procedures/tools I picked up for it.
 * Each consumer is a build*Blocks() method; inject() collects them in this order
 * and prepends the whole group ONCE, so the order is explicit here rather than
 * an accident of call order (repeated prepends would reverse it).
 *
 * ── Position: oldest, index 0, ahead of everything (recap included) ──────────
 * Injection happens AFTER liftCompactionRecap has placed the recap over the fresh
 * tail; desktop blocks go to the very head, so the two never collide. A side
 * effect worth having: the focused goal survives any compaction fold — it is not
 * part of the window, it is re-rendered every cycle.
 *
 * ── Ephemeral ────────────────────────────────────────────────────────────────
 * Runtime-only, never written to the DB, rebuilt every cycle. metadata.source
 * marks each block (SOURCE_GOAL / SOURCE_SKILL) so the UI log and future passes
 * can recognize it.
 *
 * ── Failure isolation ────────────────────────────────────────────────────────
 * A consumer that throws is logged and skipped; the cycle proceeds with whatever
 * the other consumers produced. Desktop material is never worth a failed cycle.
 */
class ContextInjectionService
{
    /** Default number of most recent progress notes shown for the focused goal. */
    private const DEFAULT_GOAL_HISTORY_LIMIT = 20;

    public function __construct(
        protected SkillLoadService $skillLoad,
        protected SkillServiceInterface $skillService,
        protected GoalServiceInterface $goalService,
        protected OptionsServiceInterface $optionsService,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Prepend all desktop material to the assembled context.
     * Returns the context unchanged when there is nothing on the desk.
     *
     * @param  array    $context  the fully assembled cycle context
     * @param  AiPreset $preset
     * @return array
     */
    public function inject(array $context, AiPreset $preset): array
    {
        $blocks = array_merge(
            $this->safely('goal', fn () => $this->buildFocusedGoalBlocks($preset), $preset),
            $this->safely('skills', fn () => $this->buildLoadedSkillBlocks($preset), $preset),
        );

        return empty($blocks) ? $context : array_merge($blocks, $context);
    }

    /**
     * @deprecated use inject() — kept so any other caller keeps working.
     *             Injects ONLY skills.
     */
    public function injectLoadedSkills(array $context, AiPreset $preset): array
    {
        $blocks = $this->safely('skills', fn () => $this->buildLoadedSkillBlocks($preset), $preset);

        return empty($blocks) ? $context : array_merge($blocks, $context);
    }

    // ── Consumer: goal in focus ───────────────────────────────────────────────

    /**
     * @return array<int, array> zero or one injected message
     */
    private function buildFocusedGoalBlocks(AiPreset $preset): array
    {
        $limit = (int) $this->optionsService->get(
            'agent_goal_focus_history_limit',
            self::DEFAULT_GOAL_HISTORY_LIMIT
        );

        $data = $this->goalService->getFocusedGoalData($preset, max(0, $limit));
        if ($data === null) {
            return [];
        }

        return [$this->makeInjectionMessage($this->renderGoalBody($data), Message::SOURCE_GOAL)];
    }

    /**
     * Render the focused goal: what, why, since when, the path so far, and how
     * long since the last step. Staleness is shown as information — whether the
     * goal is still worth the focus is the agent's call, not a timer's.
     */
    private function renderGoalBody(array $data): string
    {
        $n     = $data['number'];
        $lines = [];

        $lines[] = "[FOCUSED GOAL #{$n}: {$data['title']}]";

        if (!empty($data['motivation'])) {
            $lines[] = "Why: {$data['motivation']}";
        }

        if ($data['focused_at'] !== null) {
            $lines[] = 'In focus since: ' . $data['focused_at']->format('Y-m-d H:i');
        }

        if ($data['total_notes'] === 0) {
            $lines[] = 'Progress: no notes yet.';
        } else {
            $lines[] = "Progress ({$data['total_notes']} notes):";

            if ($data['omitted_notes'] > 0) {
                $lines[] = "  (+{$data['omitted_notes']} earlier notes — use goal show {$n} for the full history)";
            }

            foreach ($data['progress'] as $note) {
                $lines[] = '  - [' . $note['created_at']->format('Y-m-d H:i') . "] {$note['content']}";
            }

            if ($data['last_progress_at'] !== null) {
                $lines[] = 'Last progress: ' . $data['last_progress_at']->diffForHumans();
            }
        }

        $lines[] = 'Record progress as you go (number optional while in focus). '
            . 'Close with done (achieved), pause (not now) or drop (no longer wanted); unfocus to set it aside.';

        $lines[] = "[/FOCUSED GOAL #{$n}]";

        return implode("\n", $lines);
    }

    // ── Consumer: loaded skills ───────────────────────────────────────────────

    /**
     * One message per loaded skill, ordered by skill number (#1 above #2 …).
     *
     * @return array<int, array>
     */
    private function buildLoadedSkillBlocks(AiPreset $preset): array
    {
        $loadedNumbers = $this->skillLoad->loadedSkillNumbers($preset);
        if (empty($loadedNumbers)) {
            return [];
        }

        $blocks = [];
        foreach ($loadedNumbers as $number) {
            $body = $this->renderSkillBody($preset, (int) $number);
            if ($body !== null) {
                $blocks[] = $this->makeInjectionMessage($body, Message::SOURCE_SKILL);
            }
        }

        return $blocks;
    }

    /**
     * Render one loaded skill as a labelled body: title, description, all items.
     * Returns null if the skill can't be rendered (deleted mid-cycle, etc.).
     */
    private function renderSkillBody(AiPreset $preset, int $number): ?string
    {
        $data = $this->skillService->showSkillData($preset, $number);

        if (!($data['success'] ?? false)) {
            return null;
        }

        $lines = [];
        $lines[] = "[LOADED SKILL #{$data['number']}: {$data['title']}]";

        if (!empty($data['description'])) {
            $lines[] = $data['description'];
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            $lines[] = '(no items)';
        } else {
            foreach ($items as $item) {
                $lines[] = "{$data['number']}.{$item['number']}. {$item['content']}";
            }
        }

        $lines[] = "[/LOADED SKILL #{$data['number']}]";

        return implode("\n", $lines);
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    /**
     * Run one consumer; on failure log and contribute nothing.
     *
     * @param  callable(): array $build
     * @return array
     */
    private function safely(string $consumer, callable $build, AiPreset $preset): array
    {
        try {
            return $build();
        } catch (\Throwable $e) {
            $this->logger->error("ContextInjectionService: '{$consumer}' injection failed — skipped", [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Shape an ephemeral injected message. role=user so the model reads it as
     * material present in the conversation; source marks the block. NOT persisted.
     *
     * @return array{role: string, content: string, from_user_id: null, metadata: array}
     */
    private function makeInjectionMessage(string $content, string $source): array
    {
        return [
            'role'         => 'user',
            'content'      => $content,
            'from_user_id' => null,
            'metadata'     => ['source' => $source],
        ];
    }
}
