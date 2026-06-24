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
            // Pre-pass ("reasoning" / "beneath"): an extra generation pass over the
            // full assembled context BEFORE the speaking pass. Same preset, same
            // system prompt, same context — only the trailing user turn is swapped
            // for pre_pass_instruction. Output is injected via [[reasoning]] and is
            // ephemeral (never persisted).
            $table->boolean('pre_pass_enabled')
                ->default(false)
                ->after('max_context_limit_extended');

            $table->text('pre_pass_instruction')
                ->nullable()
                ->after('pre_pass_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn(['pre_pass_enabled', 'pre_pass_instruction']);
        });
    }
};
