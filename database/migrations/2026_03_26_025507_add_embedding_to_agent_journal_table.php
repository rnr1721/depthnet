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
        Schema::table('agent_journal', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('tfidf_vector');
            $table->unsignedSmallInteger('embedding_dim')->nullable()->after('embedding');

            $table->index(['preset_id', 'embedding_dim'], 'idx_journal_preset_embedding_dim');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_journal', function (Blueprint $table) {
            $table->dropIndex('idx_journal_preset_embedding_dim');
            $table->dropColumn(['embedding', 'embedding_dim']);
        });
    }
};
