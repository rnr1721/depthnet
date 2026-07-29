<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorEnactorInterface;

/**
 * BehaviorEnactorRegistry — maps a lever's kind to the enactor that applies it.
 * Direct sibling of TriggerEvaluatorRegistry.
 *
 * Enactors arrive via a tagged binding (see BehaviorServiceProvider). An unknown
 * kind returns null; the coordinator treats a pattern whose lever has no enactor as
 * NON-enacting (it leans via the placeholder as in phase 1, but pulls nothing and
 * generates no discriminating outcome) and logs once — a lever you can't apply must
 * not silently pretend to have moved something.
 *
 * Phase 2a kind resolution: a lever with a `dimension` is a mood lever. The
 * resolveKind() helper centralizes that mapping so the coordinator stays dumb about
 * lever shapes — when a second lever kind appears, only resolveKind() and the tag
 * array change.
 */
class BehaviorEnactorRegistry
{
    /** @var array<string, BehaviorEnactorInterface> */
    private array $byKind = [];

    /**
     * @param iterable<BehaviorEnactorInterface> $enactors
     */
    public function __construct(iterable $enactors = [])
    {
        foreach ($enactors as $enactor) {
            $this->register($enactor);
        }
    }

    public function register(BehaviorEnactorInterface $enactor): void
    {
        $this->byKind[$enactor->kind()] = $enactor;
    }

    public function forKind(string $kind): ?BehaviorEnactorInterface
    {
        return $this->byKind[$kind] ?? null;
    }

    /**
     * Resolve the enactor for a lever JSON by inferring its kind from shape.
     * Phase 2a: a lever carrying a `dimension` is a mood lever. Returns null when
     * the lever is empty or its kind has no registered enactor.
     */
    public function forLever(?array $lever): ?BehaviorEnactorInterface
    {
        $kind = self::resolveKind($lever);
        return $kind !== null ? $this->forKind($kind) : null;
    }

    /**
     * The lever's kind from its shape. Centralized so the coordinator never
     * inspects lever internals. Phase 2a knows one shape; extend here for more.
     */
    public static function resolveKind(?array $lever): ?string
    {
        if (empty($lever)) {
            return null;
        }

        // A mood lever is identified by a target dimension. An explicit
        // $lever['kind'] (if ever added) takes precedence.
        if (isset($lever['kind']) && is_string($lever['kind']) && $lever['kind'] !== '') {
            return $lever['kind'];
        }

        if (isset($lever['dimension'])) {
            return 'mood';
        }

        return null;
    }

    /** @return string[] kinds with a registered enactor */
    public function kinds(): array
    {
        return array_keys($this->byKind);
    }
}
