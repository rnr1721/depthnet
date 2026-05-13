<?php

namespace App\Contracts\Agent\Code;

use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * ProjectAdapterRegistryInterface
 *
 * Registry of available project adapters.
 *
 * The registry is responsible for:
 *  - Holding all adapters registered via the 'project.adapter' tag
 *  - Detecting which adapter matches a given workspace root
 *  - Providing aggregated metadata (all root markers, all ignored paths)
 */
interface ProjectAdapterRegistryInterface
{
    /**
     * Detects the most specific adapter for a given workspace root.
     *
     * Adapters are evaluated in descending priority order. The first one
     * whose matches() returns true wins.
     *
     * @param string   $root     Absolute workspace root path.
     * @param callable $executor Sandbox shell executor.
     *
     * @return ProjectAdapterInterface|null Null if no adapter matches.
     */
    public function detect(string $root, callable $executor): ?ProjectAdapterInterface;

    /**
     * Convenience wrapper around detect() returning a fingerprint.
     *
     * Returns ProjectFingerprint::unknown() when no adapter matches.
     *
     * @param string   $root
     * @param callable $executor
     *
     * @return ProjectFingerprint
     */
    public function fingerprint(string $root, callable $executor): ProjectFingerprint;

    /**
     * Returns the union of root markers from all registered adapters.
     *
     * Used by autoDetectRoot's marker scan. Order follows adapter priority,
     * with duplicates removed (first occurrence wins).
     *
     * @return array<int, string>
     */
    public function allRootMarkers(): array;

    /**
     * Returns all registered adapters in priority order (descending).
     *
     * @return array<int, ProjectAdapterInterface>
     */
    public function all(): array;
}
