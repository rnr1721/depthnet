<?php

namespace App\Services\Agent\Terminal;

use App\Contracts\Agent\Terminal\TerminalServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * TerminalService — manages a persistent tmux session inside a sandbox container.
 *
 * All Docker interaction goes through SandboxManagerInterface::executeCommand(),
 * the same path used by SandboxPlugin — no direct shell_exec or proc_open here.
 *
 * Key sending modes (sendKeys):
 *   - Plain text:   wrapped in single quotes + Enter appended automatically
 *   - Special keys: passed as-is to tmux (C-c, Up, F10, Enter, etc.)
 *   - Mixed:        "text | Key1 Key2" — text part quoted, keys appended bare
 *
 * Scrollback buffer is set to 10 000 lines via .tmux.conf in the Dockerfile.
 * captureScreen() uses `-S -{lines}` to reach into that buffer when lines > 50.
 */
class TerminalService implements TerminalServiceInterface
{
    /** Name of the tmux session inside the container */
    private const TMUX_SESSION = 'agent';

    /**
     * Known tmux special key names (case-insensitive).
     * These are passed bare to send-keys without quoting.
     */
    private const SPECIAL_KEY_PATTERN = '/^(C-[a-z\[\]\\\\^_]|M-[a-zA-Z]|\^[A-Z]|Up|Down|Left|Right|Home|End|NPage|PPage|Enter|Space|Tab|BSpace|Escape|Esc|F([1-9]|1[0-9]|20))$/i';

    public function __construct(
        protected SandboxManagerInterface $sandboxManager,
        protected LoggerInterface         $logger,
    ) {
    }

    // ── TerminalServiceInterface ──────────────────────────────────────────────

    /** @inheritDoc */
    public function ensureSession(string $sandboxId, string $user, int $timeout): ?string
    {
        $which = $this->exec($sandboxId, 'which tmux 2>/dev/null || echo missing', $user, $timeout);

        if (str_contains(trim($which ?? ''), 'missing')) {
            return 'Error: tmux is not installed in the sandbox. '
                . 'Add "RUN apt-get install -y tmux" to the sandbox Dockerfile.';
        }

        $this->exec(
            $sandboxId,
            'tmux new-session -d -s ' . self::TMUX_SESSION . ' 2>/dev/null || true',
            $user,
            $timeout
        );

        if (!$this->sessionExists($sandboxId, $user, $timeout)) {
            return 'Error: Could not start tmux session inside sandbox.';
        }

        $this->logger->info('TerminalService: session ensured', [
            'sandbox_id' => $sandboxId,
            'session'    => self::TMUX_SESSION,
        ]);

        return null;
    }

    /** @inheritDoc */
    public function sendCommand(
        string $sandboxId,
        string $command,
        string $user,
        int    $timeout,
        int    $delayMs,
        int    $captureLines
    ): ?string {
        $sendError = $this->sendKeys($sandboxId, $command, $user, $timeout, isCommand: true);
        if ($sendError) {
            return null;
        }

        usleep($delayMs * 1000);

        return $this->captureScreen($sandboxId, $user, $timeout, $captureLines);
    }

    /** @inheritDoc */
    public function sendInput(
        string $sandboxId,
        string $input,
        string $user,
        int    $timeout,
        int    $delayMs,
        int    $captureLines
    ): ?string {
        $sendError = $this->sendKeys($sandboxId, $input, $user, $timeout, isCommand: false);
        if ($sendError) {
            return null;
        }

        usleep($delayMs * 1000);

        return $this->captureScreen($sandboxId, $user, $timeout, $captureLines);
    }

    /** @inheritDoc */
    public function captureScreen(
        string $sandboxId,
        string $user,
        int    $timeout,
        int    $lines
    ): ?string {
        $scrollback = $lines > 50 ? "-S -{$lines}" : '';
        $cmd        = 'tmux capture-pane -t ' . self::TMUX_SESSION . " -p {$scrollback} 2>/dev/null";

        $output = $this->exec($sandboxId, $cmd, $user, $timeout);

        if ($output === null) {
            return null;
        }

        $trimmed = rtrim($output);

        if (empty($trimmed)) {
            return '(terminal screen is empty)';
        }

        $allLines = explode("\n", $trimmed);
        if (count($allLines) > $lines) {
            $allLines = array_slice($allLines, -$lines);
        }

        return implode("\n", $allLines);
    }

    /** @inheritDoc */
    public function sessionExists(string $sandboxId, string $user, int $timeout): bool
    {
        $result = $this->exec(
            $sandboxId,
            'tmux has-session -t ' . self::TMUX_SESSION . ' 2>/dev/null && echo ok || echo missing',
            $user,
            $timeout
        );

        return trim($result ?? '') === 'ok';
    }

    /** @inheritDoc */
    public function killSession(string $sandboxId, string $user, int $timeout): void
    {
        $this->exec(
            $sandboxId,
            'tmux kill-session -t ' . self::TMUX_SESSION . ' 2>/dev/null || true',
            $user,
            $timeout
        );

        $this->logger->info('TerminalService: session killed', [
            'sandbox_id' => $sandboxId,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build and send a tmux send-keys command.
     *
     * Three formats are supported:
     *
     *   1. Pure special key(s):  "C-c"  "Up"  "F10"  "Enter"
     *      → tmux send-keys -t agent C-c
     *      → tmux send-keys -t agent Up
     *      Multiple keys space-separated: "C-b d" → two separate keys
     *
     *   2. Mixed — text | Key1 Key2:  "yes | Enter"  "q | Enter"
     *      → tmux send-keys -t agent 'yes' Enter
     *      The pipe separates the literal text from trailing key names.
     *
     *   3. Plain text (isCommand=true):
     *      → tmux send-keys -t agent 'ls -la' Enter
     *      Enter is appended automatically.
     *
     *   4. Plain text (isCommand=false, no pipe):
     *      → tmux send-keys -t agent 'text'
     *      No Enter — caller decides whether to add it.
     *
     * Returns null on success, error string on failure.
     */
    private function sendKeys(
        string $sandboxId,
        string $input,
        string $user,
        int    $timeout,
        bool   $isCommand = false
    ): ?string {
        $input = trim($input);

        $parts   = $this->buildSendKeysParts($input, $isCommand);
        $session = self::TMUX_SESSION;
        $cmd     = "tmux send-keys -t {$session} " . implode(' ', $parts);

        $result = $this->exec($sandboxId, $cmd, $user, $timeout);

        if ($result === null) {
            return 'Error: Failed to send input to terminal session.';
        }

        return null;
    }

    /**
     * Parse input and return the tmux send-keys argument list.
     *
     * @return string[]
     */
    private function buildSendKeysParts(string $input, bool $isCommand): array
    {
        // Empty input → just Enter
        if ($input === '') {
            return ['Enter'];
        }

        // Check for pipe separator: "text | Key1 Key2"
        if (str_contains($input, '|')) {
            $pipePos  = strrpos($input, '|');
            $textPart = trim(substr($input, 0, $pipePos));
            $keysPart = trim(substr($input, $pipePos + 1));

            $parts = [];

            if ($textPart !== '') {
                $parts[] = $this->quoteText($textPart);
            }

            foreach (preg_split('/\s+/', $keysPart) as $key) {
                if ($key !== '') {
                    $parts[] = $key; // bare key names, not quoted
                }
            }

            return $parts;
        }

        // Pure special key(s) — space-separated list: "C-c"  "Up Down"  "F10"
        $tokens = preg_split('/\s+/', $input);
        $allSpecial = !empty($tokens) && array_reduce(
            $tokens,
            fn (bool $carry, string $t) => $carry && $this->isSpecialKey($t),
            true
        );

        if ($allSpecial) {
            return $tokens; // bare, no quotes, no Enter
        }

        // Plain text
        $parts = [$this->quoteText($input)];

        if ($isCommand) {
            $parts[] = 'Enter'; // commands need Enter
        }

        return $parts;
    }

    /**
     * Wrap text in single quotes, escaping any internal single quotes.
     */
    private function quoteText(string $text): string
    {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }

    /**
     * Check if a token is a recognised tmux special key name.
     */
    private function isSpecialKey(string $token): bool
    {
        return (bool) preg_match(self::SPECIAL_KEY_PATTERN, $token);
    }

    /**
     * Execute a command inside the sandbox via SandboxManagerInterface.
     * Returns stdout+stderr as string, or null on exception.
     */
    private function exec(string $sandboxId, string $command, string $user, int $timeout): ?string
    {
        try {
            $result = $this->sandboxManager->executeCommand(
                $sandboxId,
                $command,
                $user,
                $timeout
            );

            return $result->output ?? '';

        } catch (\Throwable $e) {
            $this->logger->warning('TerminalService::exec failed', [
                'sandbox_id' => $sandboxId,
                'command'    => substr($command, 0, 100),
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }
}
