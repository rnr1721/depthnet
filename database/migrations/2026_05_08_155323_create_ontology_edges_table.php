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
        Schema::create('ontology_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->constrained('ai_presets')->cascadeOnDelete();
            $table->foreignId('source_id')
                  ->constrained('ontology_nodes')
                  ->cascadeOnDelete();
            $table->foreignId('target_id')
                  ->constrained('ontology_nodes')
                  ->cascadeOnDelete();
            $table->string('relation_type', 100);
            $table->float('weight')->default(1.0);
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['preset_id', 'source_id', 'relation_type']);
            $table->index(['preset_id', 'target_id']);
            $table->index(['source_id', 'valid_until']);
            $table->index(['target_id', 'valid_until']);
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ontology_edges');
    }
};
