<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores text chunks extracted from files for vector search.
 *
 * This is the file-scoped analogue of vector_memories:
 * same TF-IDF + embedding dual-engine pattern, but bound to a file
 * rather than a preset. VectorMemory is NOT touched — this is a
 * fully independent store.
 *
 * Each file is split into chunks by its FileProcessor.
 * Chunk size and strategy depend on mime_type (page, paragraph, row, etc.)
 *
 * Search fallback chain:
 *   embedding (cosine similarity) → TF-IDF → keyword match
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('file_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('file_id')
                ->constrained('files')
                ->cascadeOnDelete();

            // Position of this chunk within the file (0-based)
            $table->unsignedSmallInteger('chunk_index');

            // Extracted text content of this chunk
            $table->text('content');

            // TF-IDF sparse vector — always computed, used as fallback
            $table->json('tfidf_vector');

            // Dense embedding vector — nullable, computed when embedding capability available
            $table->json('embedding')->nullable();
            $table->unsignedSmallInteger('embedding_dim')->nullable();

            // Top keywords extracted during processing
            $table->json('keywords')->nullable();

            $table->timestamps();

            // Lookup all chunks for a file in order
            $table->index(['file_id', 'chunk_index'], 'idx_chunks_file_order');

            // Filter chunks that have/lack embeddings (for backfill jobs)
            $table->index(['file_id', 'embedding_dim'], 'idx_chunks_file_embedding');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_chunks');
    }
};
