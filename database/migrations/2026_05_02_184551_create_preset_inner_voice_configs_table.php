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
        Schema::create('preset_inner_voice_configs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            $table->foreignId('voice_preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);

            /** How many recent messages to pass to this voice preset */
            $table->unsignedSmallInteger('context_limit')->default(10);

            /** Optional human-readable label shown in output block header */
            $table->string('label')->nullable();

            $table->timestamps();

            $table->unique(['preset_id', 'voice_preset_id']);
            $table->index(['preset_id', 'sort_order']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_inner_voice_configs');
    }
};
