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
        Schema::create('memory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->constrained('ai_presets')->onDelete('cascade');
            $table->text('content');
            $table->integer('position')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['preset_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memory_items');
    }
};
