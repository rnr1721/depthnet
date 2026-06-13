<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds spawn/parent relationship to ai_presets.
 *
 * A "spawned" preset is a temporary child created by an agent via SpawnPlugin.
 * - parent_preset_id: FK to the preset that spawned this one; CASCADE DELETE ensures
 *   children are removed automatically when the parent is deleted.
 * - is_spawned: quick boolean flag to distinguish spawned (ephemeral) presets from
 *   regular user-managed ones (e.g. hide from UI listings, skip in registries).
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            // Nullable: only spawned presets have a parent.
            $table->unsignedBigInteger('parent_preset_id')
                ->nullable()
                ->default(null)
                ->after('id');

            // Convenience flag — avoids joining/checking parent_preset_id IS NOT NULL everywhere.
            $table->boolean('is_spawned')
                ->default(false)
                ->after('parent_preset_id');

            // FK with CASCADE: deleting a parent wipes all its spawned children.
            $table->foreign('parent_preset_id', 'fk_ai_presets_parent')
                ->references('id')
                ->on('ai_presets')
                ->onDelete('cascade');

            // Index for SpawnService::listSpawns() — frequent lookup by parent.
            $table->index(['parent_preset_id', 'is_spawned'], 'idx_ai_presets_spawns');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign('fk_ai_presets_parent');
            $table->dropIndex('idx_ai_presets_spawns');
            $table->dropColumn(['parent_preset_id', 'is_spawned']);
        });
    }
};
