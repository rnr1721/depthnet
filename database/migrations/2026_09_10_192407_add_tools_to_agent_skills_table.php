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
        Schema::table('agent_skills', function (Blueprint $table) {
            // The tool/plugin names this skill un-hides when loaded. Nullable so
            // pure-knowledge skills simply carry no tools.
            $table->json('tools')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_skills', function (Blueprint $table) {
            $table->dropColumn('tools');
        });
    }
};
