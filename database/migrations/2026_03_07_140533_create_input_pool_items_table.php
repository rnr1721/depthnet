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
        Schema::create('input_pool_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preset_id');
            $table->string('source_name');
            $table->text('content');
            $table->timestamps();

            $table->foreign('preset_id')->references('id')->on('ai_presets')->onDelete('cascade');
            $table->unique(['preset_id', 'source_name']); // for upsert by source name
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_pool_items');
    }
};
