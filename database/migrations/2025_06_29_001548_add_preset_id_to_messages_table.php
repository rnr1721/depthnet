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
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('preset_id')->nullable()
                ->after('from_user_id')
                ->references('id')
                ->on('ai_presets')
                ->onDelete('cascade');

            $table->index('preset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['preset_id']);
            $table->dropIndex(['preset_id']);
            $table->dropColumn('preset_id');
        });
    }
};
