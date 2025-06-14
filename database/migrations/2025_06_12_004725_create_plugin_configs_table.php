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
        Schema::create('plugin_configs', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_name')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->json('config_data')->nullable();
            $table->json('default_config')->nullable();
            $table->string('health_status')->default('unknown');
            $table->string('version')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_result')->default(false);
            $table->text('last_test_error')->nullable();
            $table->json('test_history')->nullable();
            $table->timestamps();
            $table->index('plugin_name');
            $table->index('is_enabled');
            $table->index('health_status');
            $table->index(['is_enabled', 'health_status']);
            $table->index('last_test_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugin_configs');
    }
};
