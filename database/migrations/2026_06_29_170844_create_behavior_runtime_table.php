<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * behavior_runtime — per-preset ABS counters.
 *
 * Currently holds exactly one thing: cycle_seq, the monotonic counter of
 * completed thinking cycles for a preset. This is the SINGLE SOURCE OF TRUTH for
 * the selection time-axis. It is incremented once, atomically, at the point in
 * AgentActionsHandler where an outcome is registered — the same point where the
 * cycle is known to have completed.
 *
 * Why its own table rather than a column on ai_presets:
 *   - keeps the central preset model untouched (ABS is a bolt-on layer);
 *   - lets ABS add future per-preset counters without migrating ai_presets;
 *   - gives a clean target for an atomic `UPDATE ... SET cycle_seq = cycle_seq+1`
 *     without a full preset save (mirrors ContractRuntimeService's discipline).
 *
 * A row is created lazily on first use (nextCycleSeq) — presets that never run
 * ABS never get a row.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('behavior_runtime', function (Blueprint $table) {
            $table->foreignId('preset_id')
                ->primary()
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // Monotonic completed-cycle counter. The decay axis origin.
            $table->unsignedBigInteger('cycle_seq')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('behavior_runtime');
    }
};
