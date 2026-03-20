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
        Schema::create('preset_known_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')
                  ->constrained('ai_presets')
                  ->cascadeOnDelete();
            $table->string('source_name');
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('default_value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['preset_id', 'source_name']);
            $table->index(['preset_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_known_sources');
    }
};
