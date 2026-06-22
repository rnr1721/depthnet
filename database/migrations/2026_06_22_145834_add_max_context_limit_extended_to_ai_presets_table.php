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
            $table->unsignedInteger('max_context_limit_extended')
                ->nullable()
                ->default(null)
                ->after('max_context_limit')
                ->comment('Context message limit for extended (work) mode. Null = feature off, falls back to max_context_limit.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn('max_context_limit_extended');
        });
    }
};
