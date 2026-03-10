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
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->constrained('ai_presets')->onDelete('cascade');
            $table->string('title');
            $table->text('motivation')->nullable();
            $table->enum('status', ['active', 'paused', 'done'])->default('active');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['preset_id', 'status']);
            $table->index(['preset_id', 'position']);
        });

        Schema::create('goal_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('goals')->onDelete('cascade');
            $table->text('content');
            $table->timestamps();

            $table->index(['goal_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_progress');
        Schema::dropIfExists('goals');
    }
};
