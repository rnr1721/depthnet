<?php

namespace App\Services\Agent\Skills;

use App\Models\AiPreset;

/**
 * SkillToolGate — the thin seam the two tool builders (ToolSchemaBuilder,
 * CommandInstructionBuilder) share. It hands back the SET of tool names hidden for
 * a preset; the builder calls it ONCE before its plugin loop and skips any plugin
 * whose name is in the set.
 *
 * There is NO enabled flag: an empty hidden set means "nothing to hide" (no skill
 * declares tools, or all gated tools are currently visible) — the builders then
 * filter nothing, exactly as before the feature. Zero regression is the empty set,
 * not a branch.
 *
 * HIDING ≠ BLOCKING: this removes plugins only from the PRESENTED schema /
 * instructions. Execution is never gated (CommandExecutor untouched), so a hidden
 * tool called anyway still runs.
 *
 * Depends only on SkillLoadService — the live-registry intersection and all set
 * logic live there, so the gate stays a one-line forwarder.
 */
class SkillToolGate
{
    public function __construct(
        protected SkillLoadService $skillLoad,
    ) {
    }

    /**
     * The set of tool/plugin names to hide from this preset's schema / instructions.
     * Call once per build; filter the plugin loop with in_array($name, $set, true).
     *
     * @return string[]
     */
    public function hiddenFor(AiPreset $preset): array
    {
        return $this->skillLoad->hiddenToolNames($preset);
    }
}
