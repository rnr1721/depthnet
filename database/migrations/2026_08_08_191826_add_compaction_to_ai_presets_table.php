<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds context compaction (memory consolidation) settings to presets.
 *
 * Both fields are null/0 by default → the whole feature is inert, exactly like
 * max_context_limit_extended. "Set it and it works, leave it and nothing
 * changes" — the invariant kept across the project.
 *
 *   compressor_preset_id
 *     The preset whose system prompt drives summarisation. This IS the
 *     "compression profile": a task-state compressor is just a preset with a
 *     task-state prompt; a salience compressor, one with a salience prompt.
 *     One agent → one compressor preset → one profile. No separate profile
 *     column — that would duplicate a decision already encoded here and could
 *     drift out of sync with it. Nullable FK, nullOnDelete: if the compressor
 *     preset is deleted, compaction silently reverts to off rather than
 *     pointing at a ghost.
 *
 *   compaction_watchdog_limit
 *     Safety trigger: when the active-window message count crosses this, a
 *     compaction is forced even if the agent never called [compact] itself.
 *     Deliberately NOT reusing max_context_limit_extended — that number means
 *     "how much to CARRY in work mode" (usually larger), whereas this means
 *     "at what size to FOLD" (must sit below the ceiling, while the summary is
 *     still high quality). Opposite intents; one field cannot serve both.
 *     0/null = watchdog off; the agent-driven [compact] still works without it.
 *
 * The primary trigger (agent calls [compact] on a logical boundary) needs no
 * column — it rides the one-shot plugin-metadata flag, like Reflect.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->unsignedBigInteger('compressor_preset_id')
                ->nullable()
                ->after('cycle_prompt_preset_id');

            $table->integer('compaction_watchdog_limit')
                ->nullable()
                ->after('compressor_preset_id');

            $table->foreign('compressor_preset_id')
                ->references('id')
                ->on('ai_presets')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign(['compressor_preset_id']);
            $table->dropColumn(['compressor_preset_id', 'compaction_watchdog_limit']);
        });
    }
};
