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
        Schema::create('preset_workspace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();
            $table->string('key', 100);
            $table->longText('value');
            $table->timestamps();

            $table->unique(['preset_id', 'key']);
            $table->index('preset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_workspace');
    }
};
