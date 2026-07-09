<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wake_schedules — the agent's temporal calendar.
 *
 * A single row is one scheduled wake: a future moment (or recurring rule) at
 * which the agent is woken with a self-authored instruction. Both the agent
 * (via WakePlugin) and the user (via admin CRUD) write here — same table, two
 * doors.
 *
 * Storage discipline
 * ------------------
 * Everything is stored in WALL-CLOCK time and SECONDS. Pulses (the agent's
 * subjective time) are purely a presentation/input language — they are
 * converted to seconds/clock-time at the boundary and never persisted as a
 * unit. This keeps the dispatcher trivial (one canonical "when") and mirrors
 * how SearchDateParser keeps en/ru over a single Carbon representation.
 *
 * next_run_at is the ONLY column the dispatcher polls. It is recomputed AFTER a
 * successful enqueue, never before — so a crashed worker can neither lose a
 * wake nor fire a duplicate.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wake_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            $table->boolean('enabled')->default(true);

            // The instruction the agent gives its future self. Injected into the
            // woken cycle as a user-role message (source = wake).
            $table->text('wake_message');

            // once | interval | daily | cron
            //   once     → fire at run_at, then disable
            //   interval → fire every interval_seconds
            //   daily    → fire every day at daily_seconds-from-midnight
            //   cron     → fire per cron_expression (covers hourly / specific hours)
            $table->string('schedule_type', 16);

            // once: the absolute moment to fire.
            $table->timestamp('run_at')->nullable();

            // interval: distance between fires, in seconds. (Agent may have
            // authored it in pulses — converted on the way in.)
            $table->unsignedInteger('interval_seconds')->nullable();

            // daily: seconds from midnight for the daily fire (0..86399). Agent
            // may author it as a pulse position — converted on the way in.
            $table->unsignedInteger('daily_seconds')->nullable();

            // cron: standard 5-field expression.
            $table->string('cron_expression', 128)->nullable();

            // The single column the dispatcher polls. Indexed. Recomputed after
            // each successful enqueue.
            $table->timestamp('next_run_at')->nullable()->index();

            // Bookkeeping.
            $table->timestamp('last_fired_at')->nullable();

            // 'agent' (self-scheduled via WakePlugin) or 'user' (admin CRUD).
            // Surfaced in the [[wake_schedule]] placeholder so the agent sees who
            // decided each wake — the transparency the plugin exists for.
            $table->string('created_by_kind', 16)->default('agent');

            $table->timestamps();

            $table->index(['preset_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wake_schedules');
    }
};
