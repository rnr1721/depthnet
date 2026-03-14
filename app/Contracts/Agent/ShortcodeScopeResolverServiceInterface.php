<?php

namespace App\Contracts\Agent;

interface ShortcodeScopeResolverServiceInterface
{
    /**
     * Get the global scope identifier
     *
     * @return string
     */
    public function global(): string;

    /**
     * Build a preset scope identifier
     *
     * @param int $presetId
     * @return string
     */
    public function preset(int $presetId): string;

    /**
     * Build an ordered list of scopes for a given preset.
     * Global is always first, preset scope comes second (overrides global).
     * If presetId is null, returns only global.
     *
     * @param int|null $presetId
     * @return array
     */
    public function buildScopes(?int $presetId = null): array;
}
