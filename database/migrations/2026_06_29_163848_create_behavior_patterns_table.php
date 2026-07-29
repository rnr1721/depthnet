<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * behavior_patterns — the ABS pattern population.
 *
 * One row per competing behavior pattern owned by a preset. A pattern is a
 * LIGHT strategy ("when X, do Y with priority Z"), NOT a preset and NOT a
 * contract: a contract fires deterministically every tick, a pattern competes
 * under selection pressure and may win, starve, or be culled.
 *
 * Hot-path columns (fitness, priority, last_activation_seq, ...) are written
 * each cycle via PatternRuntimeService using the query builder directly —
 * mirroring ContractRuntimeService against agent_contracts — so per-cycle
 * fitness updates never read-modify-write a JSON blob and never race other
 * plugins sharing the preset metadata column.
 *
 * Phase 1 scope: structural triggers only, one dominant pattern per step,
 * fitness from event outcomes via eligibility-trace-lite. See ABS phase-1 doc.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('behavior_patterns', function (Blueprint $table) {
            $table->id();

            // Owner preset. Cascade: patterns are meaningless without their preset.
            $table->foreignId('preset_id')
                ->constrained('ai_presets')
                ->cascadeOnDelete();

            // Stable identifier within a preset. The agent and logs refer to a
            // pattern by (preset_id, name), never by autoincrement id.
            $table->string('name');

            // ── Definition (cold: set on author/mutate, rarely per-cycle) ──────

            // Structural trigger condition. Phase-1 forms are code-evaluated
            // (mood.x > threshold, pulse in [...], state flags). Loose JSON because
            // the shape varies by trigger kind — engine reads it per-kind, exactly
            // as ContractEngine reads trigger{} per-form.
            $table->json('trigger');

            // What the pattern intends (human/agent-readable goal of the strategy).
            // Free text — used in logs and, later, by the LLM mutation step.
            $table->text('intent')->nullable();

            // Reference to the behavior this pattern enacts when it wins.
            // Phase 1: a loose descriptor (e.g. plugin/preset code to run, or a
            // tag the speaking pass reads). A pattern is NOT the behavior — it
            // SELECTS one. Kept JSON so the binding can grow without a migration.
            $table->json('behavior')->nullable();

            // Soft constraints the behavior must respect when enacted (optional).
            $table->json('constraints')->nullable();

            // Provenance: where this pattern came from (architect | agent | mutation).
            // The "disappearing framework" metric is computed over this column.
            $table->string('provenance')->default('architect');

            // ── Selection state (hot: written per cycle) ───────────────────────

            // Current selection weight. Competition picks the highest-priority
            // ACTIVE-triggered pattern (phase 1: simple, no fitness-sharing).
            $table->float('priority')->default(1.0);

            // Accumulated fitness from outcomes via credit assignment.
            // Decays over inactivity (separate tick). The core selection signal.
            $table->float('fitness')->default(0.0);

            // How confident the system is that this pattern's fitness is meaningful
            // (low when young / few activations). Distinct from fitness itself.
            $table->float('confidence')->default(0.0);

            // Plasticity: how readily fitness moves. High early, anneals down.
            // Reserved for phase-1 tuning of learning rate per pattern.
            $table->float('plasticity')->default(1.0);

            // ── Protection (the immune / reservation mechanism) ────────────────

            // immune = cannot be DELETED or rewritten in place (must be revoked to
            // hypothesis first), mirroring the contract `vital` interlock. NOTE:
            // immunity to deletion is NOT immunity to displacement — a protected
            // pattern can still starve in the corner as priority falls and its
            // trigger stops winning. That is why immune patterns ALSO carry a
            // forced-activation quota below; the flag alone is insufficient
            // (ABS review verdict #4).
            $table->boolean('immune')->default(false);

            // Forced-activation quota: guarantee this pattern is activated at least
            // once every N completed cycles, regardless of fitness/priority. NULL
            // = no quota. This is the "reservation" for behavior with no measurable
            // exit condition (presence, silence, care) — the system's reminder of
            // its own blindness. Enforced by the decay/quota tick, not by fitness.
            $table->unsignedInteger('forced_activation_interval')->nullable();

            // ── Lifecycle ──────────────────────────────────────────────────────

            // hypothesis | active | retired. Mirrors the contract lifecycle's
            // reversible spirit: a pattern that stops fitting is retired, not
            // hard-deleted, so its history and fitness survive for inspection.
            $table->string('status')->default('active');

            // ── Activation bookkeeping (hot) ───────────────────────────────────

            // Monotonic count of how many times this pattern has EVER activated.
            // Never reset. Drives confidence and is the per-pattern age axis.
            $table->unsignedBigInteger('activation_count')->default(0);

            // The preset's global cycle_seq at this pattern's most recent
            // activation. Combined with the quota interval to decide forced
            // activation; combined with the current cycle_seq for inactivity decay.
            $table->unsignedBigInteger('last_activation_seq')->nullable();

            $table->timestamps();

            // One pattern name per preset.
            $table->unique(['preset_id', 'name']);

            // Hot lookup: "active patterns for this preset" (the competition set).
            $table->index(['preset_id', 'status']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('behavior_patterns');
    }
};
