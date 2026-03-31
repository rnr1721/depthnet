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
            $table->boolean('rag_relative_dates')->default(false)->after('rag_engine');
            $table->unsignedSmallInteger('rag_journal_limit')->default(3)->after('rag_relative_dates');
            $table->unsignedSmallInteger('rag_skills_limit')->default(3)->after('rag_journal_limit');
            $table->unsignedSmallInteger('rag_content_limit')->default(400)->after('rag_skills_limit'); // symbols for fragment
            $table->unsignedTinyInteger('rag_journal_context_window')->default(0)->after('rag_content_limit'); // journal neighbours each side, 0 = off
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn([
                'rag_relative_dates',
                'rag_journal_limit',
                'rag_skills_limit',
                'rag_content_limit',
                'rag_journal_context_window',
            ]);
        });
    }
};
