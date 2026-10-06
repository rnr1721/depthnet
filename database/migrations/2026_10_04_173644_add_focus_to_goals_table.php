<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goal focus: at most one goal per preset is "in focus" (focused_at NOT NULL).
 * Its full progress history is injected into the cycle context as desktop
 * material (see ContextInjectionService::inject).
 *
 * Also introduces the 'dropped' status. If `status` is a plain string column,
 * nothing else is needed; if it is a MySQL enum, it is widened here.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $statusIsEnum = Schema::getColumnType('goals', 'status') === 'enum';

        Schema::table('goals', function (Blueprint $table) use ($statusIsEnum) {
            $table->timestamp('focused_at')->nullable()->after('status');
            $table->index(['preset_id', 'focused_at']);

            if ($statusIsEnum) {
                $table->enum('status', ['active', 'paused', 'done', 'dropped'])
                    ->default('active')
                    ->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropIndex(['preset_id', 'focused_at']);
            $table->dropColumn('focused_at');
        });
        // 'dropped' is intentionally not removed from an enum on rollback:
        // existing dropped rows would make the column change fail.
    }
};
