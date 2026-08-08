<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds mode-aware prompt selection to preset prompts.
 *
 * context_mode marks which context mode a prompt is FOR:
 *   none      (default) — not part of mode switching; the prompt behaves exactly
 *                         as today. Every existing prompt gets this, so nothing
 *                         changes for live presets until an operator opts in.
 *   normal    — activated automatically when the agent is in normal mode
 *   extended  — activated automatically when the agent is in extended (work) mode
 *
 * This is ORTHOGONAL to is_active: is_active = "in use right now", context_mode
 * = "which mode this prompt is meant for". The mode switcher reads context_mode
 * to decide which prompt to make is_active for the current cycle.
 *
 * Invariant: at most one prompt per (preset, mode) for the non-'none' modes.
 * It is kept CONSTRUCTIVELY, not by validation: assigning normal/extended to a
 * prompt clears that same mode from every other prompt of the preset (a where
 * update), the same exclusive-flag pattern already used for is_active. Last
 * assignment wins; there is no error state to resolve.
 *
 * Default 'none' is deliberate (not 'both'/'any'): an existing prompt is not
 * "for both modes", it simply predates the feature and must stay outside it.
 * A preset where every prompt is 'none' → the switcher is inert, the active
 * prompt is never touched, behaviour is identical to today.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('preset_prompts', function (Blueprint $table) {
            $table->string('context_mode', 16)
                ->default('none')
                ->after('code');

            // Switcher looks up "the prompt of this preset for mode X" every cycle.
            $table->index(['preset_id', 'context_mode'], 'preset_prompts_preset_mode_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('preset_prompts', function (Blueprint $table) {
            $table->dropIndex('preset_prompts_preset_mode_index');
            $table->dropColumn('context_mode');
        });
    }
};
