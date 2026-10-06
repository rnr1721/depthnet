<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds context-window compaction support to messages.
 *
 * `compacted` is a VISIBILITY flag, not a deletion:
 *   - false (default) → message is in the agent's active context window
 *   - true            → message has been folded into a recap and no longer
 *                       enters the active window. The row itself STAYS in the
 *                       DB — visible to the user in the UI, and reachable by the
 *                       memory substrate (journal + vector), which is what makes
 *                       compaction non-destructive.
 *
 * The recap message that replaces a folded range carries, in its existing
 * `metadata` json, a `compaction` object describing which range it folded:
 *
 *   { "source": "compaction", "compaction": { "from_id": 100, "to_id": 150 } }
 *
 * No schema is needed for that — metadata is already json. Storing the range
 * explicitly (rather than inferring it from ordering) keeps a future "show the
 * model / user the original folded messages" path open without a second
 * migration.
 *
 * Index: context builders always query
 *   forPreset(id) → where compacted=false → orderBy id desc → limit N
 * so the compacted flag is almost always paired with preset_id. A composite
 * (preset_id, compacted) index serves that path directly. The pre-existing
 * single preset_id index is left in place — other queries (full history,
 * pagination) still use it.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('compacted')
                ->default(false)
                ->after('is_visible_to_user');

            // Composite index for the hot path: active-window assembly.
            $table->index(['preset_id', 'compacted'], 'messages_preset_compacted_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_preset_compacted_index');
            $table->dropColumn('compacted');
        });
    }
};
