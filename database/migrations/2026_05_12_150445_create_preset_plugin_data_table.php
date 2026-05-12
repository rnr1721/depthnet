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
        Schema::create('preset_plugin_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preset_id');
            $table->string('plugin_code', 64);
            $table->string('key', 128);
            $table->longText('value')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('preset_id')
                  ->references('id')->on('ai_presets')
                  ->onDelete('cascade');

            // One key per plugin per preset
            $table->unique(['preset_id', 'plugin_code', 'key']);
            $table->index(['preset_id', 'plugin_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preset_plugin_data');
    }
};
