<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard-skills compatibility (agentskills.io SKILL.md) — data layer.
 *
 * Inert on its own: every new column defaults to today's behaviour
 * (source=db, is_experience=false), so existing skills, the console,
 * injection and RAG are unchanged until the importer/guards land.
 *
 *   source        'db'   — created in DepthNet (UI, agent, knowledge)
 *                 'file' — imported from the library {shared_path}/skills/{name}/SKILL.md
 *   source_path   library dir name == spec `name` (≤64, [a-z0-9-]). NOT a full path:
 *                 the library root comes from config, so moving /shared never
 *                 invalidates rows. Unique per preset — one library skill is
 *                 attached to a preset at most once. NULL for db skills
 *                 (MySQL allows many NULLs in a unique index).
 *   source_hash   sha256 of SKILL.md at last import — drives re-import.
 *   source_meta   provenance + spec fields stored as-is (license, compatibility,
 *                 allowed_tools raw, metadata map, has_files, has_scripts, imported_at).
 *   is_experience true  — agent-learned skill, reaches RAG
 *                 false — classic skill, reached via the load console
 *
 * Also:
 *   description varchar(255) → TEXT. The spec allows up to 1024 chars, and the
 *   existing `max:500` request validation was already broken against 255.
 *   agent_skill_items.content TEXT → MEDIUMTEXT. A single SKILL.md section can
 *   exceed 64KB (long reference tables); the cutter must never truncate silently.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_skills', function (Blueprint $table) {
            // ->change() in Laravel 11+ redefines the column fully — keep nullable.
            $table->text('description')->nullable()->change();

            $table->string('source', 16)->default('db')->after('tools');
            $table->string('source_path', 64)->nullable()->after('source');
            $table->char('source_hash', 64)->nullable()->after('source_path');
            $table->json('source_meta')->nullable()->after('source_hash');
            $table->boolean('is_experience')->default(false)->after('source_meta');

            $table->unique(['preset_id', 'source_path'], 'agent_skills_preset_source_path_unique');
        });

        Schema::table('agent_skill_items', function (Blueprint $table) {
            $table->mediumText('content')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_skills', function (Blueprint $table) {
            $table->dropUnique('agent_skills_preset_source_path_unique');
            $table->dropColumn(['source', 'source_path', 'source_hash', 'source_meta', 'is_experience']);

            // NOTE: fails under strict mode if any description is now > 255 chars.
            $table->string('description', 255)->nullable()->change();
        });

        Schema::table('agent_skill_items', function (Blueprint $table) {
            // NOTE: fails under strict mode if any item is now > 64KB.
            $table->text('content')->change();
        });
    }

};
