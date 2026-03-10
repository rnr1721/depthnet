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
        Schema::table('vector_memories', function (Blueprint $table) {
            // Access counter - incremented each time memory is touched in associative chain
            $table->unsignedInteger('access_count')->default(0)->after('importance');

            // Last time this memory was accessed via search/association
            $table->timestamp('last_accessed_at')->nullable()->after('access_count');

            // Index for efficient composite scoring queries
            $table->index(['preset_id', 'access_count']);
            $table->index(['preset_id', 'last_accessed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vector_memories', function (Blueprint $table) {
            $table->dropIndex(['preset_id', 'access_count']);
            $table->dropIndex(['preset_id', 'last_accessed_at']);
            $table->dropColumn(['access_count', 'last_accessed_at']);
        });
    }
};
