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
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->boolean('defrag_enabled')->default(false)->after('rag_journal_context_window');
            $table->text('defrag_prompt')->nullable()->after('defrag_enabled');
            $table->unsignedTinyInteger('defrag_keep_per_day')->default(3)->after('defrag_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn(['defrag_enabled', 'defrag_prompt', 'defrag_keep_per_day']);
        });
    }
};
