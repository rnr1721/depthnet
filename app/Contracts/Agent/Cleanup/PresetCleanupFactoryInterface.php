<?php

namespace App\Contracts\Agent\Cleanup;

use App\Contracts\Agent\Cleanup\PresetCleanupServiceInterface;

/**
 * Factory for resolving PresetCleanupService instances.
 *
 * Exists to break the circular dependency that arises when
 * PresetCleanupService is injected directly into services that are
 * themselves dependencies of PresetCleanupService (e.g. SpawnService).
 *
 * Instead of injecting PresetCleanupServiceInterface at construction time,
 * consumers inject this factory and call make() lazily — only when a
 * cleanup operation is actually needed.
 *
 * @see PresetCleanupServiceInterface
 * @see \App\Services\Agent\Cleanup\PresetCleanupFactory
 */
interface PresetCleanupFactoryInterface
{
    /**
     * Resolve and return a fresh PresetCleanupService instance.
     *
     * The returned instance is fully initialised and ready to use.
     * Callers should not cache the result — call make() each time
     * to allow the container to manage the lifecycle.
     *
     * @return PresetCleanupServiceInterface
     */
    public function make(): PresetCleanupServiceInterface;
}
