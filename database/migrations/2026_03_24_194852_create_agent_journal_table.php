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
        Schema::create('agent_journal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->constrained('ai_presets')->cascadeOnDelete();

            // When the event was recorded — primary chronological key
            $table->timestamp('recorded_at')->index();

            // Event classification
            $table->string('type', 32)->default('observation')->index();
            // Types: action | reflection | decision | error | observation | interaction

            // Short summary — always visible in context without loading details
            $table->string('summary', 255);

            // Full event details — loaded on demand
            $table->text('details')->nullable();

            // Outcome of the event
            $table->string('outcome', 32)->nullable()->index();
            // Values: success | failure | pending | null

            // TF-IDF vector for semantic search
            $table->json('tfidf_vector')->nullable();

            $table->timestamps();

            // Compound index for date-range + preset queries
            $table->index(['preset_id', 'recorded_at']);
            $table->index(['preset_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_journal');
    }
};
