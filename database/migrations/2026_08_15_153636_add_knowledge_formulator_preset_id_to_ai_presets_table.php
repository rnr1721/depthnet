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
            $table->unsignedBigInteger('knowledge_formulator_preset_id')->nullable()
                  ->after('compressor_preset_id');
            // optional but matches nothing else here — compressor has no FK constraint,
            // so keep parity and DON'T add one unless you add one to compressor too.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn('knowledge_formulator_preset_id');
        });
    }
};
