<?php

namespace App\Services\Agent\Code\DTO;

/**
 * WorkspaceRootDetection
 *
 * Result of an automatic workspace root detection attempt.
 *
 * Returned by WorkspaceTopologyServiceInterface::autoDetectRoot().
 */
final readonly class WorkspaceRootDetection
{
    public function __construct(
        /** Absolute path to the detected workspace root. */
        public string $path,

        /**
         * Detection method used.
         *
         * Known values:
         *  - "git"           — resolved via `git rev-parse --show-toplevel`
         *  - "marker:<file>" — found a project marker in the home directory
         *  - "deep-scan"     — discovered via recursive find
         *  - "fallback"      — nothing detected, returned sandbox home
         */
        public string $method,

        /** Human-readable label for the root (e.g. "my-project", "~"). */
        public string $label,
    ) {
    }

    /**
     * Whether the detection succeeded with a non-fallback strategy.
     */
    public function isSuccessful(): bool
    {
        return $this->method !== 'fallback';
    }
}
