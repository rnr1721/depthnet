<?php

declare(strict_types=1);

namespace App\Contracts\Sandbox;

use App\Services\Sandbox\DTO\ExecutionResult;
use App\Services\Sandbox\DTO\SandboxInstance;

/**
 * Interface for managing sandbox containers
 */
interface SandboxManagerInterface
{
    /**
     * Create a new sandbox container
     *
     * @param string|null $type Sandbox type (ubuntu-full, minimal, etc.) - uses config default if null
     * @param string|null $name Custom sandbox name (auto-generated if null)
     * @param string|null $ports Ports that can use container
     * @return SandboxInstance
     * @throws SandboxException
     */
    public function createSandbox(?string $type = null, ?string $name = null, ?string $ports = null): SandboxInstance;

    /**
     * Start a stopped sandbox
     *
     * @param string $sandboxId Sandbox identifier
     * @return bool True if started successfully
     * @throws SandboxNotFoundException If sandbox not found
     * @throws SandboxException If start fails
     */
    public function startSandbox(string $sandboxId): bool;

    /**
     * Stop a running sandbox
     *
     * @param string $sandboxId Sandbox identifier
     * @param int|null $timeout Stop timeout in seconds (uses config default if null)
     * @return bool True if stopped successfully
     * @throws SandboxNotFoundException If sandbox not found
     * @throws SandboxException If stop fails or trying to stop protected container
     */
    public function stopSandbox(string $sandboxId, ?int $timeout = null): bool;

    /**
     * Execute command in sandbox
     *
     * @param string $sandboxId Sandbox identifier
     * @param string $command Command to execute
     * @param string $user User to run command as (default: sandbox-user)
     * @param int|null $timeout Execution timeout in seconds (uses config default if null)
     * @return ExecutionResult
     * @throws SandboxException
     */
    public function executeCommand(
        string $sandboxId,
        string $command,
        string $user = 'sandbox-user',
        ?int $timeout = null
    ): ExecutionResult;

    /**
     * Reset sandbox to clean state
     *
     * @param string $sandboxId Sandbox identifier
     * @param string|null $type Sandbox type to reset to (uses config default if null)
     * @return SandboxInstance
     * @throws SandboxException
     */
    public function resetSandbox(string $sandboxId, ?string $type = null): SandboxInstance;

    /**
     * Destroy sandbox container
     *
     * @param string $sandboxId Sandbox identifier
     * @return bool
     * @throws SandboxException
     */
    public function destroySandbox(string $sandboxId): bool;

    /**
     * Get sandbox status and info
     *
     * @param string $sandboxId Sandbox identifier
     * @return SandboxInstance|null
     */
    public function getSandbox(string $sandboxId): ?SandboxInstance;

    /**
     * List sandboxes with option to include stopped ones
     *
     * @param bool $includeAll Include stopped containers (default: false - only running)
     * @return SandboxInstance[]
     */
    public function listSandboxes(bool $includeAll = false): array;

    /**
     * Check if sandbox exists (running or stopped)
     *
     * @param string $sandboxId Sandbox identifier
     * @return bool
     */
    public function sandboxExists(string $sandboxId): bool;

    /**
     * Cleanup all sandboxes
     *
     * @return int Number of sandboxes cleaned up
     */
    public function cleanupAll(): int;

    /**
     * Retrieves a list of sandbox templates from .dockerfile files in the specified directory.
     *
     * For each file, attempts to extract a line starting with "TEMPLATE DESCRIPTION: ".
     * If found, the extracted description is used as the value; otherwise, null is used.
     *
     * @param string|null $path Absolute path to the templates directory. Defaults to the predefined path if null.
     *
     * @return array<string, string|null> An associative array where keys are file names (without extension),
     *                                    and values are the extracted descriptions or null.
     */
    public function getSandboxTypes(?string $path = null): array;

    /**
     * Get statistics about current sandboxes
     *
     * @param array $currentList Optional pre-fetched list of sandboxes
     * @return array Statistics with total, running, and stopped counts
     */
    public function getStats(array $currentList = []): array;

    /**
     * Get current container name if running inside container
     *
     * @return string|null Container name or null if not detected
     */
    public function getCurrentContainer(): ?string;

    // ── File delivery & environment (used by standard skills; backend-agnostic) ──

    /**
     * Absolute home directory of the sandbox user, as seen INSIDE the sandbox.
     * Callers address files relative to it and never learn how the backend maps it.
     */
    public function getHomeDirectory(string $sandboxId): string;

    /**
     * Copy a local directory into the sandbox, replacing $remotePath atomically
     * enough that a reader never sees a half-copied tree.
     *
     * $remotePath is RELATIVE to the sandbox home (e.g. ".skills/pdf"); `..` and
     * absolute paths are refused. Symlinks are skipped; the executable bit is kept.
     *
     * @param string[] $excludeNames Directory/file basenames to skip anywhere (".git", "__pycache__" …)
     * @throws SandboxException on failure
     */
    public function putDirectory(string $sandboxId, string $localDir, string $remotePath, array $excludeNames = []): void;

    /**
     * Whether a path (relative to the sandbox home) exists in the sandbox. Cheap —
     * must not require the sandbox to be running when the backend can avoid it.
     */
    public function pathExists(string $sandboxId, string $remotePath): bool;

    /**
     * One read-only look at the sandbox environment, as a flat key => value map:
     *   os, python (x.y), pip (pip3|module|none), node, sudo (1|0), venv, venv_writable,
     *   tmp_noexec (1|0), mem_limit, home, bashrc (1|0)
     * Values are '' when unknown. Requires a running sandbox.
     *
     * @return array<string,string>
     * @throws SandboxException when the sandbox cannot be reached
     */
    public function probe(string $sandboxId): array;
}
