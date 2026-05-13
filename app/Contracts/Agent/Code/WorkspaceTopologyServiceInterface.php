<?php

namespace App\Contracts\Agent\Code;

use App\Models\AiPreset;
use App\Services\Agent\Code\DTO\ProjectFingerprint;
use App\Services\Agent\Code\DTO\WorkspaceRootDetection;

/**
 * WorkspaceTopologyServiceInterface
 *
 * Provides workspace topology discovery, project fingerprinting,
 * and filesystem tree generation for AI agent sandboxes.
 *
 * Language- and framework-specific knowledge is delegated to
 * registered ProjectAdapterInterface implementations.
 */
interface WorkspaceTopologyServiceInterface
{
    /**
     * Returns the current workspace root for the given preset.
     *
     * Resolution priority:
     *  1. Persisted workspace root from metadata
     *  2. Provided default root
     *  3. Sandbox home directory
     *
     * @param AiPreset    $preset
     * @param string|null $defaultRoot Optional fallback root path.
     *
     * @return string Normalized absolute workspace path.
     */
    public function getWorkspaceRoot(
        AiPreset $preset,
        ?string $defaultRoot = null
    ): string;

    /**
     * Persists the workspace root for the preset.
     */
    public function setWorkspaceRoot(AiPreset $preset, string $path): void;

    /**
     * Removes the persisted workspace root for the preset.
     *
     * After reset, the service falls back to the configured default
     * root or the sandbox home directory.
     */
    public function resetWorkspaceRoot(AiPreset $preset): void;

    /**
     * Normalizes a filesystem path inside the sandbox.
     *
     * Expands "~", resolves ".." segments, collapses duplicate slashes,
     * removes trailing slashes, and resolves symlinks when supported.
     */
    public function normalizePath(AiPreset $preset, string $path): string;

    /**
     * Returns a human-readable label for the workspace root.
     *
     * Examples: "~", "~/my-project", "my-repo".
     */
    public function getRootLabel(AiPreset $preset, string $root): string;

    /**
     * Checks whether the given directory exists inside the sandbox.
     */
    public function directoryExists(AiPreset $preset, string $path): bool;

    /**
     * Attempts to automatically detect the most likely project root.
     *
     * Detection strategies, in order:
     *  1. Git repository root from the sandbox home directory.
     *  2. Markers from registered adapters at the home directory.
     *  3. Recursive scan for adapter markers up to a limited depth.
     *  4. Fallback to the sandbox home directory.
     *
     * @return WorkspaceRootDetection
     */
    public function autoDetectRoot(AiPreset $preset): WorkspaceRootDetection;

    /**
     * Detects the project type for the given workspace root.
     *
     * Returns ProjectFingerprint::unknown() when no adapter matches.
     */
    public function detectProjectType(AiPreset $preset, string $root): ProjectFingerprint;

    /**
     * Builds a prompt-safe workspace tree representation.
     *
     * The detected project adapter's ignoredPaths() and fileIcons()
     * are merged into the build pipeline automatically.
     *
     * @param AiPreset          $preset
     * @param string            $root            Workspace root path.
     * @param int               $maxDepth        Maximum traversal depth.
     * @param int               $maxFiles        Maximum files per directory.
     * @param array<int,string> $excludePatterns User-supplied exclude patterns.
     * @param bool              $showIcons       Whether file icons should be included.
     * @param int|null          $maxLength       Optional override for the soft size cap (chars).
     *
     * @return string Formatted workspace tree.
     */
    public function buildTree(
        AiPreset $preset,
        string $root,
        int $maxDepth = 3,
        int $maxFiles = 50,
        array $excludePatterns = [],
        bool $showIcons = true,
        ?int $maxLength = null
    ): string;

    /**
     * Lists immediate contents of a directory inside the workspace.
     *
     * Used by the "expand" command to let agents lazily explore
     * collapsed parts of the tree without rebuilding the whole map.
     *
     * @param AiPreset $preset
     * @param string   $path     Absolute path inside the sandbox.
     * @param int      $maxItems Hard cap on returned entries.
     *
     * @return string Formatted directory listing.
     */
    public function listDirectory(
        AiPreset $preset,
        string $path,
        int $maxItems = 100
    ): string;
}
