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
        Schema::create('agent_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('code', 50);
            $table->foreignId('preset_id')->constrained('ai_presets')->restrictOnDelete();
            $table->foreignId('validator_preset_id')->nullable()->constrained('ai_presets')->nullOnDelete();
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->boolean('auto_proceed')->default(false);
            $table->timestamps();

            $table->unique(['agent_id', 'code']);
            $table->index('preset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_roles');
    }
};
