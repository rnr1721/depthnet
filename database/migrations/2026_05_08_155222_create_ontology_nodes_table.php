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
        Schema::create('ontology_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->constrained('ai_presets')->cascadeOnDelete();
            $table->string('canonical_name', 100);
            $table->string('class', 50);
            $table->json('aliases')->nullable();
            $table->float('weight')->default(1.0);
            $table->timestamps();

            $table->index(['preset_id', 'canonical_name']);
            $table->index(['preset_id', 'class']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ontology_nodes');
    }
};
