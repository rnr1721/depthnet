<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 'separate' is a dead mode. Migrate live data to the current default.
        DB::table('ai_presets')
            ->where('agent_result_mode', 'separate')
            ->update(['agent_result_mode' => 'tool_calls']);

        // And fix the column default itself so new presets don't get born with a dead value.
        DB::statement("ALTER TABLE ai_presets ALTER COLUMN agent_result_mode SET DEFAULT 'tool_calls'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
