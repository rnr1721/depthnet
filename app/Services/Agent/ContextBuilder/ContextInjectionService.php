<?php

namespace App\Services\Agent\ContextBuilder;

use App\Contracts\Agent\Skills\SkillServiceInterface;
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
 * Loaded skills are working material the agent is holding right now — data for the
 * task, not part of who the agent IS. That is why they go into the HISTORY (the
 * work stream) as the oldest messages, not into the system prompt (which is
 * identity: character, reasoning, behavior, the RAG that constitutes the agent in
 * the moment). A skill un-loads by hysteresis in a few cycles; putting something
 * that transient into the identity prompt would make the self flicker. History is
 * where things appear and leave — that is the right home.
 *
 * ── Position: oldest, index 0, ahead of everything (recap included) ──────────
 * Injected as the OLDEST messages (array_unshift), so they read as "here is what's
 * open on my desk" before the agent reads its recap of the folded conversation and
 * before the fresh dialogue. Because injection happens AFTER liftCompactionRecap has
 * already placed the recap over the fresh tail, and skills go to the very head, the
 * two never collide — the recap stays where the trait put it; skills sit above it.
 * The trait is NOT touched.
 *
 * ── Ephemeral ────────────────────────────────────────────────────────────────
 * These messages are runtime-only — never written to the DB. They are rebuilt every
 * cycle from SkillLoadService::loadedSkillNumbers(), so a skill unloaded next cycle
 * simply stops being injected. metadata.source = SOURCE_SKILL marks them so future
 * passes / the UI log can recognize the block (not for positioning — position is
 * purely "index 0").
 *
 * ── General mechanism ────────────────────────────────────────────────────────
 * Skills are the FIRST consumer. Future consumers (unread telegram, sensor snapshots,
 * ambient signals) add their own inject* method and a call site; the shape (ephemeral,
 * head-of-array, source-marked) is the reusable pattern.
 */
class ContextInjectionService
{
    public function __construct(
        protected SkillLoadService $skillLoad,
        protected SkillServiceInterface $skillService,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Prepend all currently-loaded skills' bodies to the assembled context.
     *
     * Returns the context unchanged when nothing is loaded (the common path). One
     * message per loaded skill, each a self-contained, labelled block, ordered by
     * skill number (#1 above #2 …) at the head of the array.
     *
     * @param  array    $context  the fully assembled cycle context
     * @param  AiPreset $preset
     * @return array              context with loaded-skill bodies prepended
     */
    public function injectLoadedSkills(array $context, AiPreset $preset): array
    {
        $loadedNumbers = $this->skillLoad->loadedSkillNumbers($preset);
        if (empty($loadedNumbers)) {
            return $context;
        }

        // Build one message per skill, in ascending number order.
        $blocks = [];
        foreach ($loadedNumbers as $number) {
            $body = $this->renderSkillBody($preset, (int) $number);
            if ($body !== null) {
                $blocks[] = $this->makeInjectionMessage($body);
            }
        }

        if (empty($blocks)) {
            return $context;
        }

        // Prepend as the oldest messages, preserving #1 above #2 above … .
        // array_merge (blocks first) keeps their internal order and puts the whole
        // group ahead of the existing context — simpler and less error-prone than
        // repeated array_unshift, which would reverse the order.
        return array_merge($blocks, $context);
    }

    /**
     * Render one loaded skill as a labelled body: title, description, all items.
     * Returns null if the skill can't be rendered (deleted mid-cycle, etc.).
     *
     * Uses SkillService::showSkillData (pure data) rather than the message-shaped
     * showSkill, so the framing here is ours and consistent, not the CRUD reply text.
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

    /**
     * Shape an ephemeral injected message. role=user so the model reads it as
     * material present in the conversation; SOURCE_SKILL marks it as an injected
     * skill block (recognizable, never confused with a real reply). from_user_id
     * null — it originates from no user. NOT persisted.
     *
     * @return array{role: string, content: string, from_user_id: null, metadata: array}
     */
    private function makeInjectionMessage(string $content): array
    {
        return [
            'role'         => 'user',
            'content'      => $content,
            'from_user_id' => null,
            'metadata'     => ['source' => Message::SOURCE_SKILL],
        ];
    }
}
