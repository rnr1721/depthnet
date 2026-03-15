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
        Schema::create('preset_command_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();
            $table->foreignId('message_id')
                ->constrained('messages')
                ->cascadeOnDelete();
            $table->longText('results');
            $table->timestamps();

            $table->index(['preset_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_command_results');
    }
};
