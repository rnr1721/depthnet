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
        Schema::create('vector_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preset_id');
            $table->text('content');
            $table->json('tfidf_vector');
            $table->json('keywords')->nullable();
            $table->float('importance', 8, 2)->default(1.0);
            $table->timestamps();
            $table->foreign('preset_id')->references('id')->on('ai_presets')->onDelete('cascade');
            $table->index(['preset_id', 'created_at']);
            $table->index(['preset_id', 'importance']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_memories');
    }
};
