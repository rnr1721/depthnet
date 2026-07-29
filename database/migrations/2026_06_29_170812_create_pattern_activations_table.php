<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pattern_activations — the eligibility-trace log.
 *
 * One row per (pattern activated, in a given completed cycle). This is the
 * record credit assignment reads when an outcome lands: "which patterns were
 * active in the cycles leading up to this outcome, and how many cycles ago?"
 *
 * The decay axis is cycle_seq — a monotonic per-preset counter of completed
 * thinking cycles, owned by ABS itself (NOT pulse, NOT mood.total_cycles, NOT
 * message id). Pulse is wall-clock-cyclic and optional; the selection mechanism
 * must work in any DepthNet install, including plain agent software with no
 * subjective time. So the axis lives here, unconditionally.
 *
 * eligibility-trace-lite: when an outcome with value v lands at cycle C, each
 * activation row with credited = false and (C - cycle_seq) within the horizon
 * receives credit  v · gamma^(C - cycle_seq), applied to its pattern's fitness,
 * then is marked credited. Known weakness (accepted, flagged in the doc): this
 * rewards "janitor" patterns that activated late by proximity, not by proven
 * causation. Counterfactual causality is a phase-2+ open item.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pattern_activations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // The activated pattern. Cascade: an activation has no meaning once
            // its pattern is gone. (Retirement keeps the row; only hard-delete,
            // which immune patterns resist, removes it.)
            $table->foreignId('pattern_id')
                ->constrained('behavior_patterns')
                ->cascadeOnDelete();

            // The preset's global completed-cycle counter at the moment this
            // activation was recorded. The decay axis. Monotonic, ABS-owned.
            $table->unsignedBigInteger('cycle_seq');

            // Why this activation happened:
            //   trigger — won the competition on its trigger this cycle
            //   forced  — activated by the quota despite not winning (immune)
            // Lets analysis separate earned activations from reserved ones.
            $table->string('reason')->default('trigger');

            // Credit-assignment state. An activation is eligible for exactly one
            // credit application; once an outcome pays it, credited flips true so
            // a later outcome in the same horizon can't double-pay it.
            $table->boolean('credited')->default(false);

            // The credit actually applied when paid (audit; nullable until paid).
            $table->float('credit_applied')->nullable();

            // Optional diagnostic pulse label IF subjective time is enabled — for
            // human-readable logs only, NEVER used in the decay math. Naturalistic
            // observability without a dependency.
            $table->string('pulse_label')->nullable();

            $table->timestamps();

            // Hot path for credit assignment: "uncredited activations for this
            // preset at/after cycle (C - horizon)", newest first.
            $table->index(['preset_id', 'credited', 'cycle_seq']);

            // Per-pattern history scans.
            $table->index(['pattern_id', 'cycle_seq']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pattern_activations');
    }
};
