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
            $table->unsignedBigInteger('cycle_prompt_preset_id')
                ->nullable()
                ->after('voice_preset_id');

            $table->foreign('cycle_prompt_preset_id')
                ->references('id')
                ->on('ai_presets')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign(['cycle_prompt_preset_id']);
            $table->dropColumn('cycle_prompt_preset_id');
        });
    }
};
