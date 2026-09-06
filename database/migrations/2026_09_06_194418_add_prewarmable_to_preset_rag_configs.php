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
            // Prewarmable: this level does NOT depend on the next user message,
            // so it may be assembled ahead of time (warm-up) while the agent is
            // idle. Background memory, not reactive. Default false = current
            // behaviour (always assembled synchronously in the cycle).
            $table->boolean('prewarmable')->default(false)->after('context_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('preset_rag_configs', function (Blueprint $table) {
            $table->dropColumn('prewarmable');
        });
    }
};
