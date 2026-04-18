<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('ai_presets')
            ->where('agent_result_mode', 'inline')
            ->update(['agent_result_mode' => 'internal']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
