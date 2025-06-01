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
        Schema::create('ai_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('engine_name', 100);
            $table->text('system_prompt', 5000)->nullable();
            $table->text('notes', 2000)->nullable();
            $table->integer('dopamine_level')->default(5);
            $table->text('plugins_disabled', 255)->nullable();
            $table->json('engine_config');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'name']);
            $table->index('is_default');
            $table->index('engine_name');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_presets');
    }
};
