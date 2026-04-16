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
        // 1. Drop the old global plugin_configs table
        if (Schema::hasTable('plugin_configs')) {
            Schema::drop('plugin_configs');
        }

        // 2. Drop the inline plugin_configs JSON column on ai_presets
        if (Schema::hasColumn('ai_presets', 'plugin_configs')) {
            Schema::table('ai_presets', function (Blueprint $table) {
                $table->dropColumn('plugin_configs');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentional no-op. The legacy schema is gone; recreating empty
        // structures would just give a false sense of reversibility.
    }
};
