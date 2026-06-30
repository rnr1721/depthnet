<?php

namespace App\Providers;

use App\Contracts\Agent\Behavior\BehaviorCoordinatorInterface;
use App\Contracts\Agent\Behavior\BehaviorDecayRunnerInterface;
use App\Contracts\Agent\Behavior\BehaviorPatternServiceInterface;
use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\Behavior\CreditAssignerInterface;
use App\Contracts\Agent\Behavior\PatternSelectorInterface;
use App\Services\Agent\Behavior\BehaviorCoordinator;
use App\Services\Agent\Behavior\BehaviorDecayRunner;
use App\Services\Agent\Behavior\BehaviorEnactorRegistry;
use App\Services\Agent\Behavior\BehaviorPatternService;
use App\Services\Agent\Behavior\BehaviorRuntimeService;
use App\Services\Agent\Behavior\CreditAssigner;
use App\Services\Agent\Behavior\Enactors\MoodEnactor;
use App\Services\Agent\Behavior\Enactors\MoodVectorService;
use App\Services\Agent\Behavior\LeverOutcomeDiscriminator;
use App\Services\Agent\Behavior\PatternSelector;
use App\Services\Agent\Behavior\TriggerEvaluatorRegistry;
use App\Services\Agent\Behavior\Triggers\MoodTriggerEvaluator;
use App\Services\Agent\Behavior\Triggers\PulseTriggerEvaluator;
use Illuminate\Support\ServiceProvider;

/**
 * BehaviorServiceProvider — wires the ABS subsystem.
 *
 * Pattern mirrors the contract subsystem and the rest of DepthNet:
 * interface → implementation throughout, so every consumer depends on the
 * contract, not the concrete.
 *
 *   - trigger evaluators are tagged, collected into a registry (like trace
 *     readers). A new trigger kind = a new evaluator class + one line in the tag
 *     array. The selector never changes.
 *   - the coordinator is the single public entry point (its interface is the
 *     seam Agent and AgentActionsHandler depend on); the runtime/selector/credit
 *     parts are plumbing it composes, each behind its own contract.
 *
 * NOTE on StateVectorInterface: MoodTriggerEvaluator depends on it. That binding
 * is already provided by the contract subsystem (MoodStateVectorAdapter or
 * NullStateVector). ABS reuses it rather than re-binding — both subsystems read
 * the same emotional vector through the same seam. If the contract provider is
 * ever removed, bind StateVectorInterface here too.
 *
 * TriggerEvaluatorRegistry stays concrete — it is a collection/registry, exactly
 * like TraceReaderRegistry, which also has no interface in this codebase.
 */
class BehaviorServiceProvider extends ServiceProvider
{
    /** Trigger evaluator implementations, collected into the registry by tag. */
    private const TRIGGER_EVALUATORS = [
        MoodTriggerEvaluator::class,
        PulseTriggerEvaluator::class,
    ];

    /** Lever enactor implementations, collected into the registry by tag. */
    private const ENACTORS = [
        MoodEnactor::class,
    ];

    public function register(): void
    {
        // ── Tagged trigger evaluators → registry ─────────────────────────────
        foreach (self::TRIGGER_EVALUATORS as $class) {
            $this->app->singleton($class);
        }
        $this->app->tag(self::TRIGGER_EVALUATORS, 'behavior.trigger_evaluators');

        $this->app->singleton(TriggerEvaluatorRegistry::class, function ($app) {
            return new TriggerEvaluatorRegistry(
                $app->tagged('behavior.trigger_evaluators')
            );
        });

        $this->app->singleton(MoodVectorService::class);

        // ── Tagged lever enactors → registry (mirrors trigger evaluators) ─────
        foreach (self::ENACTORS as $class) {
            $this->app->singleton($class);
        }
        $this->app->tag(self::ENACTORS, 'behavior.enactors');

        $this->app->singleton(BehaviorEnactorRegistry::class, function ($app) {
            return new BehaviorEnactorRegistry(
                $app->tagged('behavior.enactors')
            );
        });

        // ── Discriminator (concrete; explicit singleton for parity) ──────────
        $this->app->singleton(LeverOutcomeDiscriminator::class);

        // ── Core services: interface → implementation (singletons) ───────────
        $this->app->singleton(BehaviorRuntimeServiceInterface::class, BehaviorRuntimeService::class);
        $this->app->singleton(BehaviorPatternServiceInterface::class, BehaviorPatternService::class);
        $this->app->singleton(CreditAssignerInterface::class, CreditAssigner::class);
        $this->app->singleton(PatternSelectorInterface::class, PatternSelector::class);
        $this->app->singleton(BehaviorDecayRunnerInterface::class, BehaviorDecayRunner::class);
        $this->app->singleton(BehaviorCoordinatorInterface::class, BehaviorCoordinator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\BehaviorDecayCommand::class,
            ]);
        }
    }
}
