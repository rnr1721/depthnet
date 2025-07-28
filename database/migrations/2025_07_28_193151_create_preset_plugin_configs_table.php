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
        Schema::create('preset_plugin_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->constrained('ai_presets')->onDelete('cascade');
            $table->string('plugin_name');
            $table->boolean('is_enabled')->default(true);
            $table->json('config_data')->nullable();
            $table->json('default_config')->nullable();
            $table->timestamps();

            $table->unique(['preset_id', 'plugin_name']);
            $table->index(['preset_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_plugin_configs');
    }
};
