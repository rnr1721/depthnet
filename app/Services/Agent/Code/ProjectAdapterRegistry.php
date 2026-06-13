<?php

namespace App\Services\Agent\Code;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Contracts\Agent\Code\ProjectAdapterRegistryInterface;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * ProjectAdapterRegistry
 *
 * Holds project adapters tagged via the container and sorts them
 * by descending priority on construction.
 */
class ProjectAdapterRegistry implements ProjectAdapterRegistryInterface
{
    /** @var array<int, ProjectAdapterInterface> */
    private array $adapters;

    /**
     * @param iterable<ProjectAdapterInterface> $adapters Container-tagged adapters.
     */
    public function __construct(iterable $adapters)
    {
        $list = [];
        foreach ($adapters as $adapter) {
            $list[] = $adapter;
        }

        usort(
            $list,
            static fn (ProjectAdapterInterface $a, ProjectAdapterInterface $b)
                => $b->priority() <=> $a->priority()
        );

        $this->adapters = $list;
    }

    /**
     * @inheritDoc
     */
    public function detect(string $root, callable $executor): ?ProjectAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->matches($root, $executor)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function fingerprint(string $root, callable $executor): ProjectFingerprint
    {
        $adapter = $this->detect($root, $executor);

        return $adapter
            ? $adapter->fingerprint()
            : ProjectFingerprint::unknown();
    }

    /**
     * @inheritDoc
     */
    public function allRootMarkers(): array
    {
        $markers = [];
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->rootMarkers() as $marker) {
                $markers[$marker] = true;
            }
        }

        return array_keys($markers);
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
