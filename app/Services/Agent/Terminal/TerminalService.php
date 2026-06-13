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

    private const SPECIAL_KEYS = [
        'Up', 'Down', 'Left', 'Right',
        'Home', 'End', 'NPage', 'PPage',
        'Enter', 'Space', 'Tab', 'BSpace',
        'Escape', 'Esc',
    ];

    private const CTRL_META_PATTERN = '/^(C-[a-z\[\]\\\\^_]|M-[a-zA-Z]|\^[A-Z]|F([1-9]|1[0-9]|20))$/i';

    private const DEFAULT_PROMPT_PATTERN = '/(?:^|\n)[^\n]*[$#>]\s*$/';

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
        int    $captureLines,
        bool   $waitForPrompt = true,
        ?string $promptPattern = null
    ): ?string {
        $sendError = $this->sendKeys($sandboxId, $command, $user, $timeout, true);
        if ($sendError) {
            return null;
        }

        if ($waitForPrompt) {
            $this->waitForPrompt($sandboxId, $user, $timeout, $delayMs, $promptPattern);
        } else {
            usleep($delayMs * 1000);
        }

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
        $sendError = $this->sendKeys($sandboxId, $input, $user, $timeout, true);
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
     * Supported formats:
     *   1. Pure special keys:  "C-c", "Up Down", "F10"
     *      → tmux send-keys -t agent C-c
     *   2. Mixed: "text | Enter", "q | Enter"
     *      → tmux send-keys -t agent -l 'text' Enter
     *   3. Plain text with Enter: "ls -la", "yes"
     *      → tmux send-keys -t agent -l 'ls -la' Enter
     *   4. Plain text without Enter (appendEnter=false):
     *      → tmux send-keys -t agent -l 'text'
     *
     * Returns null on success, error string on failure.
     */
    private function sendKeys(
        string $sandboxId,
        string $input,
        string $user,
        int    $timeout,
        bool $appendEnter = false
    ): ?string {
        $input = rtrim($input, "\r\n");
        $parts = $this->buildSendKeysParts($input, $appendEnter);
        $session = self::TMUX_SESSION;

        // Separating: literal text and control keys
        $literal = [];
        $controls = [];
        $isLiteral = false;

        foreach ($parts as $part) {
            if ($part === '-l') {
                $isLiteral = true;
                continue;
            }
            if ($isLiteral) {
                $literal[] = $part;
                $isLiteral = false;
            } else {
                $controls[] = $part;
            }
        }

        // 1. Sending literal text
        if (!empty($literal)) {
            $cmd = "tmux send-keys -t {$session} -l " . implode(' ', $literal);
            $result = $this->exec($sandboxId, $cmd, $user, $timeout);
            if ($result === null) {
                return 'Error: Failed to send literal text to terminal.';
            }
            usleep(50 * 1000); // 50ms — даём tmux обработать literal
        }

        // 2. Sending literal text
        if (!empty($controls)) {
            $cmd = "tmux send-keys -t {$session} " . implode(' ', $controls);
            $result = $this->exec($sandboxId, $cmd, $user, $timeout);
            if ($result === null) {
                return 'Error: Failed to send keys to terminal.';
            }
            usleep(50 * 1000); // 50ms — allow the shell to process keypresses
        }

        return null;
    }

    /**
     * Build tmux send-keys arguments.
     * Uses -l (literal) for text to avoid shell escaping edge cases.
     *
     * @return string[]
     */
    private function buildSendKeysParts(string $input, bool $appendEnter = false): array
    {
        if ($input === '') {
            return ['Enter'];
        }

        if (preg_match('/\s\|\s/', $input)) {
            $pipePos  = strrpos($input, '|');
            $textPart = trim(substr($input, 0, $pipePos));
            $keysPart = trim(substr($input, $pipePos + 1));

            $keyTokens = preg_split('/\s+/', $keysPart);
            $allKeys = !empty($keyTokens) && array_reduce(
                $keyTokens,
                fn (bool $carry, string $t) => $carry && $this->isSpecialKey($t),
                true
            );

            if ($allKeys) {
                $parts = [];
                if ($textPart !== '') {
                    // -l — literal mode: не требует shell escaping
                    $parts[] = '-l';
                    $parts[] = $this->quoteText($textPart);
                }
                foreach ($keyTokens as $key) {
                    $parts[] = $key;
                }
                return $parts;
            }
        }

        $tokens = preg_split('/\s+/', $input);
        $allSpecial = !empty($tokens) && array_reduce(
            $tokens,
            fn (bool $carry, string $t) => $carry && $this->isSpecialKey($t),
            true
        );

        if ($allSpecial) {
            return $tokens;
        }

        // Plain text — literal mode
        $parts = ['-l', $this->quoteText($input)];

        if ($appendEnter) {
            $parts[] = 'Enter';
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
        return in_array($token, self::SPECIAL_KEYS, true)
            || (bool) preg_match(self::CTRL_META_PATTERN, $token);
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

    /**
     * Wait for the shell prompt to appear on the terminal screen.
     * This is a heuristic that checks the last 2 lines of the screen for a prompt pattern.
     * It polls every 200ms until it sees a prompt or reaches maxWaitMs.
     * If the prompt is detected before delayMs, it waits the remaining time to ensure a consistent delay.
     *
     * @param  string   $sandboxId
     * @param  string   $user
     * @param  int      $timeout
     * @param  int      $delayMs     Total milliseconds to wait (including time until prompt appears)
     */
    private function waitForPrompt(
        string $sandboxId,
        string $user,
        int    $timeout,
        int    $delayMs,
        ?string $promptPattern = null
    ): void {
        $pattern = $promptPattern ?? self::DEFAULT_PROMPT_PATTERN;
        $maxWaitMs = max($delayMs, 5000);
        $pollIntervalMs = 200;
        $elapsed = 0;

        while ($elapsed < $maxWaitMs) {
            usleep($pollIntervalMs * 1000);
            $elapsed += $pollIntervalMs;

            $screen = $this->captureScreenLow($sandboxId, $user, $timeout, 2);

            if ($screen !== null && preg_match($pattern, rtrim($screen, "\r\n"))) {
                usleep(200 * 1000);
                return;
            }
        }

        usleep(max(0, $delayMs * 1000 - $elapsed * 1000));
    }

    /**
     * Capture screen without the extra logging and trimming of captureScreen().
     * Used internally for waitForPrompt to check the last 2 lines of the screen.
     *
     * @param  string   $sandboxId
     * @param  string   $user
     * @param  int      $timeout
     * @param  int      $lines       Lines to capture
     * @return string|null           Screen content, or null if session does not exist
     */
    private function captureScreenLow(
        string $sandboxId,
        string $user,
        int    $timeout,
        int    $lines
    ): ?string {
        $cmd = 'tmux capture-pane -t ' . self::TMUX_SESSION . ' -p 2>/dev/null';
        $output = $this->exec($sandboxId, $cmd, $user, $timeout);

        if ($output === null || $output === '') {
            return null;
        }

        $allLines = explode("\n", rtrim($output));
        $lastLines = array_slice($allLines, -$lines);

        return implode("\n", $lastLines);
    }

}
