<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private array $columns = [
         'rhasspy_enabled',
         'rhasspy_url',
         'rhasspy_tts_voice',
         'rhasspy_incoming_enabled',
         'rhasspy_incoming_token',
     ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('ai_presets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->boolean('rhasspy_enabled')->default(false)->after('allow_handoff_from');
            $table->string('rhasspy_url')->nullable()->after('rhasspy_enabled');
            $table->string('rhasspy_tts_voice')->nullable()->after('rhasspy_url');
            $table->boolean('rhasspy_incoming_enabled')->default(false)->after('rhasspy_tts_voice');
            $table->string('rhasspy_incoming_token')->nullable()->after('rhasspy_incoming_enabled');
        });
    }
};
