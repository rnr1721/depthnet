<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create a prompt table
        Schema::create('preset_prompts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('preset_id');
            $table->string('code', 50);
            $table->text('content');
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['preset_id', 'code']);
            $table->index('preset_id');

            $table->foreign('preset_id')
                ->references('id')
                ->on('ai_presets')
                ->onDelete('cascade');
        });

        // 2. Add active_prompt_id to ai_presets (without FK yet - there is no data yet)
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->unsignedBigInteger('active_prompt_id')->nullable()->after('engine_name');
        });

        // 3. Migrate existing system_prompt → preset_prompts
        //    For each preset, create a prompt with the code 'default'
        $presets = DB::table('ai_presets')->select('id', 'system_prompt')->get();

        foreach ($presets as $preset) {
            $promptId = DB::table('preset_prompts')->insertGetId([
                'preset_id'   => $preset->id,
                'code'        => 'default',
                'content'     => $preset->system_prompt ?? '',
                'description' => 'Migrated from system_prompt',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // We immediately enter active_prompt_id
            DB::table('ai_presets')
                ->where('id', $preset->id)
                ->update(['active_prompt_id' => $promptId]);
        }

        // 4. Now you can add FK - all the records are already filled in
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->foreign('active_prompt_id')
                ->references('id')
                ->on('preset_prompts')
                ->onDelete('set null'); // защита: при удалении промпта — NULL, логика в сервисе
        });

        // 5. Dropping the old field
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropColumn('system_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Returning system_prompt
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->text('system_prompt')->nullable()->after('engine_name');
        });

        // 2. Recovering data from active_prompt
        $presets = DB::table('ai_presets')
            ->leftJoin('preset_prompts', 'ai_presets.active_prompt_id', '=', 'preset_prompts.id')
            ->select('ai_presets.id', 'preset_prompts.content as system_prompt')
            ->get();

        foreach ($presets as $preset) {
            DB::table('ai_presets')
                ->where('id', $preset->id)
                ->update(['system_prompt' => $preset->system_prompt ?? '']);
        }

        // 3. Drop FK and active_prompt_id column
        Schema::table('ai_presets', function (Blueprint $table) {
            $table->dropForeign(['active_prompt_id']);
            $table->dropColumn('active_prompt_id');
        });

        // 4. Drop the prompt table (cascade will remove the FK from preset_prompts)
        Schema::dropIfExists('preset_prompts');
    }
};
