<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores capability provider configurations per preset.
 *
 * Each row binds a capability type (embedding, image, audio, ...)
 * to a specific driver and its settings for a given preset.
 * This allows different presets to use different embedding models,
 * image generators, etc. — all configurable via the GUI.
 *
 * Example rows:
 *   preset_id=1, capability=embedding, driver=novita, config={model: baai/bge-m3}
 *   preset_id=1, capability=image,     driver=novita, config={model: flux/schnell}
 *   preset_id=2, capability=embedding, driver=openai, config={model: text-embedding-3-small}
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('preset_capability_configs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // Capability type: 'embedding', 'image', 'audio', ...
            $table->string('capability', 64);

            // Driver identifier: 'novita', 'openai', 'cohere', ...
            // Must match CapabilityProviderInterface::getDriverName()
            $table->string('driver', 64);

            // Driver-specific configuration (api_key, model, base_url, etc.)
            // Stored as JSON — each driver defines its own schema via getConfigFields()
            $table->json('config')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One config per capability per preset
            $table->unique(['preset_id', 'capability'], 'uq_preset_capability');

            $table->index(['preset_id', 'capability', 'is_active'], 'idx_preset_capability_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_capability_configs');
    }
};
