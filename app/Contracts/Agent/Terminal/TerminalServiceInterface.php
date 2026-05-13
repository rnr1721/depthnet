<?php

namespace App\Contracts\Agent\Terminal;

/**
 * TerminalServiceInterface — persistent tmux terminal inside a sandbox.
 *
 * Manages the lifecycle of a named tmux session within an assigned sandbox
 * container, and exposes the three core operations the TerminalPlugin needs:
 * send a command, send raw input, and capture the current screen.
 *
 * Session state (initialized flag, monitor on/off) is stored externally
 * by the caller (PluginMetadataService) — this service is stateless.
 */
interface TerminalServiceInterface
{
    /**
     * Ensure the tmux session exists inside the container.
     * Creates it if missing. Verifies existence if already initialized.
     *
     * @param  string   $sandboxId   Running sandbox container ID
     * @param  string   $user        User to run tmux as inside the container
     * @param  int      $timeout     Docker exec timeout in seconds
     * @return string|null           null on success, error message on failure
     */
    public function ensureSession(string $sandboxId, string $user, int $timeout): ?string;

    /**
     * Send a shell command to the tmux session and return the screen output
     * after a short delay.
     *
     * @param  string   $sandboxId
     * @param  string   $command     Shell command to execute
     * @param  string   $user
     * @param  int      $timeout     Docker exec timeout
     * @param  int      $delayMs     Milliseconds to wait before capturing output
     * @param  int      $captureLines Lines to capture from screen
     * @param  bool     $waitForPrompt Whether to wait for the shell prompt after sending the command
     * @param  string|null $promptPattern Regex pattern to detect shell prompt
     * @return string|null           Captured output, or null on failure
     */
    public function sendCommand(
        string $sandboxId,
        string $command,
        string $user,
        int    $timeout,
        int    $delayMs,
        int    $captureLines,
        bool   $waitForPrompt = true,
        ?string $promptPattern = null
    ): ?string;

    /**
     * Send raw text to stdin (for answering interactive prompts).
     * Empty string sends a bare Enter.
     *
     * @param  string   $sandboxId
     * @param  string   $input       Text to send (empty = Enter only)
     * @param  string   $user
     * @param  int      $timeout
     * @param  int      $delayMs
     * @param  int      $captureLines
     * @return string|null           Captured screen after input, or null on failure
     */
    public function sendInput(
        string $sandboxId,
        string $input,
        string $user,
        int    $timeout,
        int    $delayMs,
        int    $captureLines
    ): ?string;

    /**
     * Capture the current terminal screen content.
     *
     * @param  string   $sandboxId
     * @param  string   $user
     * @param  int      $timeout
     * @param  int      $lines       Lines to capture (>50 uses scrollback buffer)
     * @return string|null           Screen content, or null if session does not exist
     */
    public function captureScreen(
        string $sandboxId,
        string $user,
        int    $timeout,
        int    $lines
    ): ?string;

    /**
     * Check whether the tmux session is currently running.
     *
     * @param  string $sandboxId
     * @param  string $user
     * @param  int    $timeout
     * @return bool
     */
    public function sessionExists(string $sandboxId, string $user, int $timeout): bool;

    /**
     * Kill the existing tmux session (if any).
     *
     * @param  string $sandboxId
     * @param  string $user
     * @param  int    $timeout
     * @return void
     */
    public function killSession(string $sandboxId, string $user, int $timeout): void;
}
