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
            $table->string('preset_code', 50)->nullable()->unique()->after('created_by');
            $table->string('preset_code_next', 50)->nullable()->after('preset_code');
            $table->string('default_call_message', 1000)->nullable()->after('preset_code_next');
            $table->integer('before_execution_wait')->default(5)->after('default_call_message');
            $table->enum('error_behavior', ['stop', 'continue', 'fallback'])->default('stop')->after('before_execution_wait');
            $table->boolean('allow_handoff_to')->default(true)->after('error_behavior');
            $table->boolean('allow_handoff_from')->default(true)->after('allow_handoff_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn('preset_code');
            $table->dropColumn('preset_code_next');
            $table->dropColumn('default_call_message');
            $table->dropColumn('before_execution_wait');
            $table->dropColumn('error_behavior');
            $table->dropColumn('allow_handoff_to');
            $table->dropColumn('allow_handoff_from');
        });
    }
};
