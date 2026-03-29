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
        Schema::table('person_memories', function (Blueprint $table) {
            // Semantic search fields — same pattern as journal_entries
            $table->json('tfidf_vector')->nullable()->after('content');
            $table->json('embedding')->nullable()->after('tfidf_vector');
            $table->unsignedInteger('embedding_dim')->nullable()->after('embedding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('person_memories', function (Blueprint $table) {
            $table->dropColumn(['tfidf_vector', 'embedding', 'embedding_dim']);
        });
    }
};
