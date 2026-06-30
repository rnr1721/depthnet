<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * add_lever_to_behavior_patterns — ABS phase 2a: pattern enactment.
 *
 * Phase 1 patterns only LEANED: the dominant pattern's intent was exposed via
 * the [[behavior]] placeholder, a soft influence on the speaking pass. They did
 * not pull anything. Because the cycle outcome (spoke / committed) did not depend
 * on WHICH pattern led, fitness was a proxy for "how often a pattern leads", not
 * "is this pattern better" (the honest boundary named in behavior.md, phase 1).
 *
 * Phase 2a gives a pattern a LEVER: a small, machine-readable nudge it applies to
 * one state-vector (mood) dimension when it leads. The same lever serves as the
 * discriminator's reference: we know exactly how much the lever pushed, so credit
 * is paid only for the mood movement BEYOND the push (Adaliya's "credit the
 * surplus the moment gave, not the intention we injected"). This makes a pattern
 * a TESTABLE HYPOTHESIS — "in such moments I become more tender" is confirmed by
 * the moment's surplus contribution, not by the lever drawing its own proof.
 *
 * Shape (validated by BehaviorPatternService::validate + StoreBehaviorRequest):
 *   { "dimension": "tenderness", "delta": 0.15 }
 *
 * NULL lever = a phase-1 pattern: leans via placeholder, enacts nothing, never
 * generates a discriminating outcome. Full backward compatibility — every
 * existing pattern keeps working untouched.
 *
 * Loose JSON, exactly like trigger/behavior/constraints, so the lever can grow a
 * second kind later (a non-mood lever) without a migration — the enactor registry
 * resolves the kind, mirroring trigger evaluators.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('behavior_patterns', function (Blueprint $table) {
            // The enactment lever. NULL = cosmetic phase-1 pattern (no enactment,
            // no discriminating outcome). Placed beside behavior/constraints — the
            // other loose-JSON definition fields it conceptually pairs with.
            $table->json('lever')->nullable()->after('constraints');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('behavior_patterns', function (Blueprint $table) {
            $table->dropColumn('lever');
        });
    }
};
