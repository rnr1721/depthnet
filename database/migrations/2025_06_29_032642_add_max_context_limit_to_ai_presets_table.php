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
            $table->integer('max_context_limit')->default(8)->after('loop_interval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn('max_context_limit');
        });
    }
};
