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
        Schema::table('vector_memories', function (Blueprint $table) {
            // Dense float vector from embedding model.
            // NULL = embedding not yet computed, TF-IDF fallback is used.
            $table->json('embedding')->nullable()->after('tfidf_vector');

            // Dimension stored separately to avoid count() on every read
            // and to identify which model produced the vector.
            $table->unsignedSmallInteger('embedding_dim')->nullable()->after('embedding');

            $table->index(['preset_id', 'embedding_dim'], 'idx_preset_embedding_dim');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vector_memories', function (Blueprint $table) {
            $table->dropIndex('idx_preset_embedding_dim');
            $table->dropColumn(['embedding', 'embedding_dim']);
        });
    }
};
