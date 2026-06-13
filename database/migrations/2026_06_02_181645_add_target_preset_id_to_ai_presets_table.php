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
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->unsignedBigInteger('target_preset_id')
                ->nullable()
                ->default(null)
                ->after('id')
                ->comment('If set, plugin commands execute in the context of this preset instead of own');

            $table->string('target_plugins_whitelist')
                ->nullable()
                ->default(null)
                ->after('target_preset_id')
                ->comment('Comma-separated list of plugins allowed to execute in foreign preset context');

            $table->foreign('target_preset_id')
                ->references('id')
                ->on('ai_presets')
                ->nullOnDelete(); // target deleted — silently null, executor falls back to self
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign(['target_preset_id']);
            $table->dropColumn(['target_preset_id', 'target_plugins_whitelist']);
        });
    }
};
