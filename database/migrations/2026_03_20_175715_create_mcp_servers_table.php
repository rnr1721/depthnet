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
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preset_id');
            $table->string('name', 100);          // human-readable label, e.g. "GitHub"
            $table->string('server_key', 100);     // slug used in commands, e.g. "github"
            $table->string('url');                 // SSE/HTTP endpoint
            $table->string('transport')->default('sse'); // sse | stdio (future)
            $table->json('headers')->nullable();   // Authorization etc.
            $table->boolean('is_enabled')->default(true);
            $table->boolean('added_by_agent')->default(false);
            $table->string('health_status')->default('unknown'); // unknown|ok|error
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('tools_cache')->nullable(); // cached tools list from server
            $table->timestamp('tools_cached_at')->nullable();
            $table->timestamps();

            $table->foreign('preset_id')
                ->references('id')
                ->on('ai_presets')
                ->onDelete('cascade');

            $table->unique(['preset_id', 'server_key']);
            $table->index(['preset_id', 'is_enabled']);
            $table->index('added_by_agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
