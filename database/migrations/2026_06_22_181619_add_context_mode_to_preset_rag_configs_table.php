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
        Schema::table('preset_rag_configs', function (Blueprint $table) {
            $table->string('context_mode', 16)
                ->default('both')
                ->after('is_primary')
                ->comment('Which context profile activates this RAG config: normal, extended, or both');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('preset_rag_configs', function (Blueprint $table) {
            $table->dropColumn('context_mode');
        });
    }
};
