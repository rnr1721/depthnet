<?php

namespace App\Contracts\Agent\Code;

use App\Services\Agent\Code\DTO\LspDiagnostic;
use App\Services\Agent\Code\DTO\LspLocation;
use App\Services\Agent\Code\DTO\LspStartResult;
use App\Services\Agent\Code\DTO\LspStatus;

/**
 * LspServiceInterface — Language Server Protocol integration.
 *
 * Provides code intelligence through a language-specific LSP server
 * running inside the sandbox. The server is started lazily on first
 * use and stays alive across thinking cycles.
 *
 * All paths are absolute sandbox paths.
 * All methods require an active sandbox with lsp-runner installed.
 */
interface LspServiceInterface
{
    // ── Lifecycle ─────────────────────────────────────────────────

    /**
     * Start the LSP server for the given sandbox.
     *
     * Auto-detects language from project files (go.mod, composer.json, etc.)
     * and launches the appropriate language server via lsp-runner.
     *
     * @param  string $sandboxId
     * @param  string $user      Sandbox user to run commands as
     * @param  int    $timeout   Command timeout in seconds
     * @param  string $workingDir Optional working directory for the LSP server (default: /home/sandbox-user)
     * @return LspStartResult
     */
    public function start(string $sandboxId, string $user, int $timeout, string $workingDir = '/home/sandbox-user'): LspStartResult;

    /**
     * Stop the LSP server.
     *
     * @param  string $sandboxId
     * @param  string $user
     * @param  int    $timeout
     * @return bool   True if stopped successfully
     */
    public function stop(string $sandboxId, string $user, int $timeout): bool;

    /**
     * Get current LSP server status.
     *
     * @param  string $sandboxId
     * @param  string $user
     * @param  int    $timeout
     * @return LspStatus
     */
    public function status(string $sandboxId, string $user, int $timeout): LspStatus;

    /**
     * Check if LSP server is currently running.
     *
     * @param  string $sandboxId
     * @param  string $user
     * @param  int    $timeout
     * @return bool
     */
    public function isRunning(string $sandboxId, string $user, int $timeout): bool;

    // ── Code Intelligence ─────────────────────────────────────────

    /**
     * Find all references to a symbol in a file.
     *
     * Example: find all usages of "User" class in User.php
     *
     * @param  string $sandboxId
     * @param  string $file      Absolute path to the file
     * @param  string $symbol    Symbol name to find
     * @param  string $user
     * @param  int    $timeout
     * @return LspLocation[]     List of locations where the symbol is referenced
     */
    public function references(string $sandboxId, string $file, string $symbol, string $user, int $timeout): array;

    /**
     * Go to the definition of a symbol.
     *
     * Example: where is "fetchData" function defined?
     *
     * @param  string $sandboxId
     * @param  string $file
     * @param  string $symbol
     * @param  string $user
     * @param  int    $timeout
     * @return LspLocation|null  Definition location, or null if not found
     */
    public function definition(string $sandboxId, string $file, string $symbol, string $user, int $timeout): ?LspLocation;

    /**
     * Get hover information for a symbol (type, documentation, signature).
     *
     * Example: what parameters does "calculatePrice" accept?
     *
     * @param  string $sandboxId
     * @param  string $file
     * @param  string $symbol
     * @param  string $user
     * @param  int    $timeout
     * @return string            Hover text (may be empty)
     */
    public function hover(string $sandboxId, string $file, string $symbol, string $user, int $timeout): string;

    /**
     * List all symbols (classes, functions, methods, etc.) in a file or directory.
     *
     * @param  string $sandboxId
     * @param  string $path      Absolute path to file or directory
     * @param  string $user
     * @param  int    $timeout
     * @return LspLocation[]     List of symbol locations
     */
    public function symbols(string $sandboxId, string $path, string $user, int $timeout): array;

    /**
     * Get diagnostics (errors, warnings, hints) for a file or directory.
     *
     * @param  string $sandboxId
     * @param  string $path      Absolute path to file or directory
     * @param  string $user
     * @param  int    $timeout
     * @return LspDiagnostic[]   List of diagnostics (empty if no issues)
     */
    public function diagnostics(string $sandboxId, string $path, string $user, int $timeout): array;
}
