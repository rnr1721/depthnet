<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * preset_prompt_versions — version history for preset prompts.
 *
 * Every edit to a PresetPrompt writes an immutable snapshot here.
 * The head (preset_prompts.content) stays the source of truth for the
 * runtime; this table is an insert-only history layer beside it.
 *
 * Design notes:
 *  - Snapshots store FULL content (not diffs). Prompts are small (≤20k);
 *    full-copy restore is a plain SELECT, no patch-chain reconstruction.
 *  - `version` is a monotonic per-prompt counter (v1, v2, v3…), computed
 *    as MAX(version)+1 inside the same transaction as the edit.
 *  - `revert` never mutates or deletes history — it appends a NEW version
 *    whose content equals the reverted-to version. History stays monotonic
 *    and non-destructive; the revert itself is visible.
 *  - v1 is the prompt's original state, written at prompt creation, so a
 *    revert back to the very beginning is always possible.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preset_prompt_versions', function (Blueprint $table) {
            $table->id();

            // Owner prompt. Versions are meaningless without their prompt,
            // so they cascade away with it.
            $table->foreignId('prompt_id')
                ->constrained('preset_prompts')
                ->cascadeOnDelete();

            // Monotonic per-prompt version number (v1, v2, v3…).
            $table->unsignedInteger('version');

            // Full snapshot of the prompt content AFTER this edit.
            $table->longText('content');

            // Optional short label for the edit — diff header, commit-style
            // message, or the search/replace summary that produced it.
            $table->string('edit_summary', 500)->nullable();

            // Actor class that produced this version.
            // 'agent'  — the model edited its own prompt (e.g. Ada via Reach)
            // 'human'  — edited from the admin UI
            // 'system' — created by seeding, migration, or automated process
            // Kept as string (not native enum) to avoid ALTER pain and stay
            // consistent with input_mode / error_behavior style in this codebase.
            $table->string('edited_by', 16)->default('system');

            // Concrete human editor, when edited_by = 'human'.
            // nullOnDelete: if the user is removed, the version survives and
            // simply loses its user link — history is never destroyed.
            $table->foreignId('editor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Guarantees monotonicity at the DB level and doubles as the
            // lookup index for "history of this prompt, newest first".
            $table->unique(['prompt_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_prompt_versions');
    }
};
