<?php

namespace App\Contracts\Agent\Code;

use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * ProjectAdapterInterface
 *
 * Adapter for a specific project type or framework.
 *
 * Each adapter encapsulates everything the workspace topology service
 * needs to know about a particular language/framework:
 *  - How to detect it (root markers + custom check)
 *  - How to identify it (fingerprint with id/icon/label)
 *  - What to ignore in tree building
 *  - Which file icons are specific to it
 *
 * Adapters are registered via the 'project.adapter' container tag
 * and resolved through ProjectAdapterRegistryInterface.
 *
 * To add support for a new language/framework, implement this interface
 * and register the class in AppServiceProvider's tag list. No service
 * code needs to be modified.
 */
interface ProjectAdapterInterface
{
    /**
     * Stable machine-readable identifier.
     *
     * Used for logging, testing, and configuration. Must be unique
     * across registered adapters. Lowercase, no spaces.
     *
     * Examples: "laravel", "symfony", "php", "nextjs", "vite",
     *           "node", "python"
     */
    public function id(): string;

    /**
     * Priority for detection ordering.
     *
     * Higher = checked first. More specific adapters (Laravel)
     * should outrank their generic siblings (PHP).
     *
     * Convention:
     *  - 100+ : specific frameworks  (Laravel, Next.js)
     *  -  50  : generic language fallbacks (PHP, Node, Python)
     */
    public function priority(): int;

    /**
     * Filenames whose presence at the workspace root indicates this project.
     *
     * Used both for fingerprinting and for autoDetectRoot's marker scan.
     * Generic adapters typically declare core language markers
     * (composer.json, package.json, pyproject.toml).
     * Specific adapters declare framework markers (artisan, next.config.js).
     *
     * @return array<int, string>
     */
    public function rootMarkers(): array;

    /**
     * Performs the final identity check inside the sandbox.
     *
     * Called when at least one of this adapter's rootMarkers() exists
     * in the candidate root. The adapter may run additional checks
     * (e.g. parse composer.json, check for nested config files)
     * via the supplied executor.
     *
     * The executor signature is: fn(string $command, int $timeoutSeconds): string
     * It returns the trimmed stdout of the command, or empty string on failure.
     *
     * @param string   $root     Absolute root path inside the sandbox.
     * @param callable $executor Shell executor scoped to the sandbox.
     *
     * @return bool True if this adapter claims the project.
     */
    public function matches(string $root, callable $executor): bool;

    /**
     * Returns the project fingerprint for prompt display.
     */
    public function fingerprint(): ProjectFingerprint;

    /**
     * Paths and patterns this adapter recommends excluding from the tree.
     *
     * Returned items follow the same syntax as user-provided exclude patterns:
     *  - Plain names match directories anywhere ("vendor", "node_modules").
     *  - Patterns with "*" match filenames ("*.pyc").
     *
     * The tree builder merges these with user-supplied excludes.
     *
     * @return array<int, string>
     */
    public function ignoredPaths(): array;

    /**
     * Adapter-specific file icons.
     *
     * Mapping of filename suffix to emoji icon. The suffix is matched
     * via str_ends_with, so longer/more specific suffixes (e.g. ".blade.php")
     * should be listed before shorter ones (e.g. ".php").
     *
     * Returned icons override the built-in icon table for matched files.
     *
     * @return array<string, string>
     */
    public function fileIcons(): array;
}
