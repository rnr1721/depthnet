<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a 'domain' column to vector_memories.
 *
 * Domains are agent-managed namespaces inside a single preset's vector memory.
 * Examples: 'global', 'elequs', 'depthnet_arch', 'relationships'.
 *
 * Design choices:
 *  - String, not FK: no separate domain table. Domain exists for as long
 *    as it has records. Empty domain = vanished domain. Reduces CRUD/UI
 *    burden and lets the agent freely create/dispose of domains.
 *  - Default 'global': all existing records become part of the 'global'
 *    domain on backfill. No NULL semantics — every record has a domain.
 *  - Length 64: long enough for descriptive slugs ('elequs_b2b_pricing'),
 *    short enough to keep index size sane.
 *  - Composite index (preset_id, domain): the only access pattern.
 *    Domain is never queried across presets.
 */

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vector_memories', function (Blueprint $table) {
            $table->string('domain', 64)
                ->default('global')
                ->after('preset_id');

            $table->index(['preset_id', 'domain'], 'idx_vector_memories_preset_domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vector_memories', function (Blueprint $table) {
            $table->dropIndex('idx_vector_memories_preset_domain');
            $table->dropColumn('domain');
        });
    }
};
