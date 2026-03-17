<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')
                  ->constrained('ai_presets')
                  ->cascadeOnDelete();

            $table->string('title');
            $table->string('description')->nullable();

            // Sequential number within the preset, e.g. #1, #2, #3
            // Gaps are allowed — the number never changes after creation
            $table->unsignedInteger('number');

            $table->timestamps();

            $table->unique(['preset_id', 'number']);
            $table->index('preset_id');
        });

        Schema::create('agent_skill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')
                  ->constrained('agent_skills')
                  ->cascadeOnDelete();

            // Sequential number within the skill, e.g. 1.1, 1.2
            $table->unsignedInteger('number');

            $table->text('content');

            // Pre-computed TF-IDF vector for semantic search
            $table->json('tfidf_vector')->nullable();

            $table->timestamps();

            $table->unique(['skill_id', 'number']);
            $table->index('skill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_skill_items');
        Schema::dropIfExists('agent_skills');
    }
};
