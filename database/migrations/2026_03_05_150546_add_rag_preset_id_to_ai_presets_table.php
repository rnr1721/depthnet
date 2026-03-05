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
            $table->foreignId('rag_preset_id')
                ->nullable()
                ->after('preset_code_next')
                ->constrained('ai_presets')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign(['rag_preset_id']);
            $table->dropColumn('rag_preset_id');
        });
    }
};
