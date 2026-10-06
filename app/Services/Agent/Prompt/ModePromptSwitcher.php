<?php

namespace App\Services\Agent\Prompt;

use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Prompt\ModePromptSwitcherInterface;
use App\Contracts\Agent\Prompt\PresetPromptServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetPrompt;
use Psr\Log\LoggerInterface;

/**
 * See interface for the contract and the ownership/conflict rationale.
 *
 * Activity lives on the PRESET (active_prompt_id), not on the prompt row — there
 * is no is_active column. Activation goes through PresetPromptService::setActive,
 * the single code path that maintains active_prompt_id (it writes the preset and
 * does NOT snapshot a version, so mode switching never pollutes prompt history).
 * This service never writes active_prompt_id directly.
 */
class ModePromptSwitcher implements ModePromptSwitcherInterface
{
    public function __construct(
        protected PresetPrompt                 $promptModel,
        protected PresetPromptServiceInterface $promptService,
        protected ContextModeResolverInterface $contextModeResolver,
        protected LoggerInterface              $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function syncActivePromptForMode(AiPreset $preset): bool
    {
        $mode = $this->contextModeResolver->isExtended($preset)
            ? PresetPrompt::MODE_EXTENDED
            : PresetPrompt::MODE_NORMAL;

        // The prompt (if any) tagged for the current mode. The one-per-mode
        // invariant (kept in PresetService::syncPrompts) guarantees at most one,
        // so first() is unambiguous.
        $target = $this->promptModel
            ->where('preset_id', $preset->getId())
            ->where('context_mode', $mode)
            ->first();

        // No prompt tagged for this mode → inert. Leave the active prompt as-is.
        // This is the default for every preset whose prompts are all 'none', and
        // the case where only one of the two modes is tagged. Do NOT fall back to
        // touching the active prompt — inertness is what preserves today's
        // behaviour and keeps this from fighting PromptPlugin when the operator
        // hasn't opted in.
        if ($target === null) {
            return false;
        }

        // Already active → idempotent no-op. Activity is on the preset
        // (active_prompt_id), NOT on the prompt row. This guard matters: the
        // method runs every cycle, so without it we'd write the preset on each
        // tick and thrash the same path PromptPlugin uses.
        if ((int) $preset->active_prompt_id === (int) $target->getId()) {
            return false;
        }

        // setActive(AiPreset, int): void — the single path that maintains
        // active_prompt_id. It throws if the prompt isn't found in the preset;
        // guard so a transient mismatch never breaks the cycle.
        try {
            $this->promptService->setActive($preset, $target->getId());
        } catch (\Throwable $e) {
            $this->logger->warning('ModePromptSwitcher: setActive failed', [
                'preset_id' => $preset->getId(),
                'prompt_id' => $target->getId(),
                'error'     => $e->getMessage(),
            ]);
            return false;
        }

        // Keep the in-memory preset consistent for the rest of this cycle: the
        // builders read $preset->active_prompt_id after this, and setActive only
        // persisted it — refresh the attribute so downstream sees the new value
        // without a full reload.
        $preset->active_prompt_id = $target->getId();

        $this->logger->info('ModePromptSwitcher: switched active prompt for mode', [
            'preset_id' => $preset->getId(),
            'mode'      => $mode,
            'prompt_id' => $target->getId(),
            'code'      => $target->code,
        ]);

        return true;
    }
}
