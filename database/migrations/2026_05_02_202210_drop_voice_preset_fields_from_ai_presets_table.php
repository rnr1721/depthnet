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
            $table->dropForeign(['voice_preset_id']);
            $table->dropColumn(['voice_preset_id', 'voice_context_limit']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->unsignedBigInteger('voice_preset_id')->nullable();
            $table->unsignedSmallInteger('voice_context_limit')->default(5);

            $table->foreign('voice_preset_id')
                ->references('id')
                ->on('ai_presets')
                ->nullOnDelete();
        });
    }
};
