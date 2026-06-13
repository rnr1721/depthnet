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
        Schema::create('ontology_node_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')
                  ->constrained('ontology_nodes')
                  ->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value_scalar')->nullable();
            $table->foreignId('value_node_id')
                  ->nullable()
                  ->constrained('ontology_nodes')
                  ->nullOnDelete();
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('valid_until')->nullable();

            $table->index(['node_id', 'key']);
            $table->index(['node_id', 'valid_until']);
            $table->index('value_node_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ontology_node_properties');
    }
};
