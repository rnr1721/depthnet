<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preset_rag_configs', function (Blueprint $table) {
            $table->id();

            // Owner preset — when deleted, all its RAG configs go with it
            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // RAG preset — when deleted, this config row disappears too
            $table->foreignId('rag_preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // Drag-and-drop order; lower = earlier in the pipeline
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Only the primary config participates in RagQueryPlugin
            // (agent-provided queries). Secondary configs always use
            // model-formulated queries.
            $table->boolean('is_primary')->default(false);

            // Which data sources this config searches.
            // Recognised values: vector_memory, journal, skills, persons
            // Example: ["vector_memory", "journal", "skills"]
            $table->json('sources')->nullable();

            // ── Search settings (migrated from ai_presets) ────────────────────

            // flat | associative
            $table->string('rag_mode', 20)->default('flat');

            // tfidf | embedding
            $table->string('rag_engine', 20)->default('tfidf');

            // How many recent messages the RAG preset sees when formulating queries
            $table->unsignedSmallInteger('rag_context_limit')->default(5);

            // Max vector memory results returned per query
            $table->unsignedSmallInteger('rag_results')->default(5);

            // Max journal entries returned per query
            $table->unsignedSmallInteger('rag_journal_limit')->default(3);

            // Max skill items returned
            $table->unsignedSmallInteger('rag_skills_limit')->default(3);

            // Max characters per memory / journal entry in formatted output
            $table->unsignedSmallInteger('rag_content_limit')->default(400);

            // How many neighbour journal entries to include around each hit (0 = off)
            $table->unsignedSmallInteger('rag_journal_context_window')->default(0);

            // Show relative dates ("3d ago") alongside absolute dates
            $table->boolean('rag_relative_dates')->default(false);

            $table->timestamps();

            // One preset can have many configs, but each (preset, rag_preset) pair
            // should be unique — no point adding the same RAG preset twice.
            $table->unique(['preset_id', 'rag_preset_id']);

            $table->index(['preset_id', 'sort_order']);
        });

        // ── Drop RAG columns from ai_presets ─────────────────────────────────
        // No backward-compat migration — users reassign RAG configs via the UI.
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign(['rag_preset_id']);
            $table->dropColumn([
                'rag_preset_id',
                'rag_mode',
                'rag_engine',
                'rag_context_limit',
                'rag_results',
                'rag_journal_limit',
                'rag_skills_limit',
                'rag_content_limit',
                'rag_journal_context_window',
                'rag_relative_dates',
            ]);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore RAG columns on ai_presets
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->foreignId('rag_preset_id')->nullable()->constrained('ai_presets')->nullOnDelete();
            $table->string('rag_mode', 20)->default('flat');
            $table->string('rag_engine', 20)->default('tfidf');
            $table->unsignedSmallInteger('rag_context_limit')->default(5);
            $table->unsignedSmallInteger('rag_results')->default(5);
            $table->unsignedSmallInteger('rag_journal_limit')->default(3);
            $table->unsignedSmallInteger('rag_skills_limit')->default(3);
            $table->unsignedSmallInteger('rag_content_limit')->default(400);
            $table->unsignedSmallInteger('rag_journal_context_window')->default(0);
            $table->boolean('rag_relative_dates')->default(false);
        });

        Schema::dropIfExists('preset_rag_configs');
    }
};
