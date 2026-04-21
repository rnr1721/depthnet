<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\Terminal\TerminalServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * TerminalPlugin — persistent interactive terminal inside a sandbox container.
 *
 * Unlike SandboxPlugin (one-shot exec per command), TerminalPlugin maintains
 * a live tmux session inside the assigned sandbox. Working directory, running
 * processes, environment variables, and shell history all survive between
 * thinking cycles.
 *
 * The agent controls a "monitor" — whether the current terminal screen is
 * injected into its system prompt automatically via [[terminal_screen]]:
 *
 *   [terminal on][/terminal]   — enable auto-inject (monitor on)
 *   [terminal off][/terminal]  — disable auto-inject (monitor off)
 *
 * Even with the monitor off, the terminal keeps running. The agent can
 * always take a manual snapshot with [terminal screen][/terminal].
 *
 * All tmux/docker logic lives in TerminalService. This class handles only
 * plugin lifecycle, config, command dispatch, and placeholder registration.
 *
 * Key sending (via [terminal send]):
 *   - Plain text:            "yes"  → sent as literal string + Enter
 *   - Special key:           "C-c"  → sent bare (Ctrl+C)
 *   - Multiple special keys: "Up Up Enter" → sent as sequence
 *   - Mixed text + keys:     "q | Enter"  → 'q' then Enter key
 *
 * Requirements:
 *   - A sandbox with tmux installed must be assigned to the preset.
 *   - Add [[terminal_screen]] to the system prompt where the agent should
 *     see the screen output (resolves to empty string when monitor is off).
 *
 * Commands:
 *   [terminal]command[/terminal]          — run a command and return output
 *   [terminal send]text or keys[/terminal] — send input or special keys
 *   [terminal screen][/terminal]          — capture current screen (last N lines)
 *   [terminal screen]100[/terminal]       — capture last 100 lines of scrollback
 *   [terminal on][/terminal]              — turn monitor on ([[terminal_screen]] becomes active)
 *   [terminal off][/terminal]             — turn monitor off (terminal still runs)
 *   [terminal status][/terminal]          — show session info and monitor state
 *   [terminal reset][/terminal]           — kill session and start fresh
 */
class TerminalPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME   = 'terminal';
    private const META_MONITOR = 'monitor_on';
    private const META_INIT    = 'session_initialized';

    public function __construct(
        protected TerminalServiceInterface               $terminalService,
        protected PresetSandboxServiceInterface          $presetSandboxService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected LoggerInterface                        $logger,
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Persistent interactive terminal inside the assigned sandbox. '
            . 'Working directory, running processes, and shell history survive between thinking cycles. '
            . 'Control a monitor to auto-inject the current screen into terminal_screen (in system message).';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            '── Basic usage ──────────────────────────────────────────────',
            'Run a shell command:        [terminal]ls -la /var/www[/terminal]',
            'Capture current screen:     [terminal screen][/terminal]',
            'Capture last 100 lines:     [terminal screen]100[/terminal]',
            'Turn monitor ON:            [terminal on][/terminal]   (terminal_screen injected every cycle)',
            'Turn monitor OFF:           [terminal off][/terminal]  (session keeps running)',
            'Show session status:        [terminal status][/terminal]',
            'Reset (fresh bash):         [terminal reset][/terminal]',
            '',
            '── Sending input and special keys ───────────────────────────',
            '  [terminal send] supports three formats:',
            '',
            '  1. Plain text — sent as literal string, Enter appended automatically:',
            '     [terminal send]yes[/terminal]',
            '     [terminal send]my password[/terminal]',
            '     [terminal send][/terminal]   ← bare Enter (e.g. skip a prompt)',
            '',
            '  2. Special key(s) — passed directly to tmux, no Enter appended:',
            '     [terminal send]C-c[/terminal]          ← Ctrl+C (interrupt)',
            '     [terminal send]C-d[/terminal]          ← Ctrl+D (EOF / exit)',
            '     [terminal send]C-z[/terminal]          ← Ctrl+Z (suspend)',
            '     [terminal send]Up[/terminal]           ← arrow up (history)',
            '     [terminal send]Down[/terminal]         ← arrow down',
            '     [terminal send]F10[/terminal]          ← F10 (e.g. quit mc)',
            '     [terminal send]Escape[/terminal]       ← Escape',
            '     [terminal send]Tab[/terminal]          ← Tab (autocomplete)',
            '     [terminal send]Up Up Enter[/terminal]  ← sequence of keys',
            '',
            '  3. Mixed — literal text followed by | and key name(s):',
            '     [terminal send]q | Enter[/terminal]    ← type q then press Enter',
            '     [terminal send]yes | Enter[/terminal]  ← same as plain "yes" but explicit',
            '',
            '  Special key reference:',
            '    Ctrl:       C-a  C-b  C-c  C-d  C-z  C-[ …',
            '    Alt/Meta:   M-a  M-b  M-x …',
            '    Arrows:     Up  Down  Left  Right',
            '    Navigation: Home  End  NPage(PgDn)  PPage(PgUp)',
            '    Common:     Enter  Space  Tab  BSpace  Escape  Esc',
            '    Function:   F1 … F20',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => self::PLUGIN_NAME,
            'description' => 'Persistent interactive terminal (tmux) inside the sandbox. '
                . 'Maintains shell state between cycles — working directory, running processes, history. '
                . 'Use [terminal on] to auto-inject screen into system prompt each cycle, [terminal off] to stop. '
                . 'When monitor is ON, terminal_screen in the system prompt always shows the current screen.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Terminal operation to perform',
                        'enum'        => ['execute', 'send', 'screen', 'on', 'off', 'status', 'reset'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',

                            'execute: shell command to run, e.g. "cd /var/www && git status".',
                            'Enter is appended automatically.',

                            'send: three formats supported —',
                            '(1) Plain text: sent literally + Enter. Example: "yes", "my answer", "" (bare Enter).',
                            '(2) Special key(s): passed bare to tmux, NO Enter.',
                            'Examples: "C-c" (Ctrl+C), "C-d" (EOF), "C-z" (suspend),',
                            '"Up" (arrow), "Down", "Left", "Right",',
                            '"F1"-"F20", "Tab", "Enter", "Escape", "Esc", "BSpace",',
                            '"Home", "End", "NPage" (PgDn), "PPage" (PgUp),',
                            '"M-x" (Alt+x), "Up Up Enter" (key sequence).',
                            '(3) Mixed — text | Key: "q | Enter" sends literal q then presses Enter.',

                            'screen: optional line count from scrollback, e.g. "100". Default from config.',

                            'on/off/status/reset: leave empty.',
                        ]),
                    ],
                ],
                'required' => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Terminal error. Check sandbox status with [terminal status][/terminal].';
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Terminal Plugin',
                'description' => 'Requires a sandbox with tmux installed and assigned to this preset.',
                'required'    => false,
            ],
            'capture_lines' => [
                'type'        => 'number',
                'label'       => 'Default screen capture lines',
                'description' => 'How many lines to show when capturing the terminal screen.',
                'min'         => 10,
                'max'         => 500,
                'value'       => 50,
                'required'    => false,
            ],
            'command_timeout' => [
                'type'        => 'number',
                'label'       => 'Command timeout (seconds)',
                'description' => 'Max time allowed for a single docker exec call.',
                'min'         => 1,
                'max'         => 60,
                'value'       => 5,
                'required'    => false,
            ],
            'capture_delay_ms' => [
                'type'        => 'number',
                'label'       => 'Capture delay (milliseconds)',
                'description' => 'How long to wait after sending a command before capturing output. Increase for slow commands.',
                'min'         => 200,
                'max'         => 5000,
                'value'       => 800,
                'required'    => false,
            ],
            'sandbox_user' => [
                'type'        => 'text',
                'label'       => 'Sandbox user',
                'description' => 'User to run tmux commands as inside the container.',
                'value'       => 'sandbox-user',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['capture_lines'])) {
            $v = (int) $config['capture_lines'];
            if ($v < 10 || $v > 500) {
                $errors['capture_lines'] = 'Must be between 10 and 500.';
            }
        }

        if (isset($config['command_timeout'])) {
            $v = (int) $config['command_timeout'];
            if ($v < 1 || $v > 60) {
                $errors['command_timeout'] = 'Must be between 1 and 60 seconds.';
            }
        }

        if (isset($config['capture_delay_ms'])) {
            $v = (int) $config['capture_delay_ms'];
            if ($v < 200 || $v > 5000) {
                $errors['capture_delay_ms'] = 'Must be between 200 and 5000 ms.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'          => false,
            'capture_lines'    => 50,
            'command_timeout'  => 5,
            'capture_delay_ms' => 800,
            'sandbox_user'     => 'sandbox-user',
        ];
    }

    // ── Shortcode registration ────────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'terminal_screen',
            'Current terminal screen (injected when monitor is on)',
            function () use ($context) {
                if (!$this->isMonitorOn($context)) {
                    return '';
                }

                $sandboxId = $this->getSandboxId($context);
                if (!$sandboxId) {
                    return '[terminal_screen: no sandbox assigned]';
                }

                $screen = $this->terminalService->captureScreen(
                    $sandboxId,
                    $context->get('sandbox_user', 'sandbox-user'),
                    (int) $context->get('command_timeout', 5),
                    (int) $context->get('capture_lines', 50),
                );

                if ($screen === null) {
                    return '[terminal_screen: session not running — use [terminal]any command[/terminal]]';
                }

                return "[TERMINAL SCREEN]\n{$screen}\n[END TERMINAL SCREEN]";
            },
            $scope
        );
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * Default execute — run a shell command in the tmux session.
     * [terminal]ls -la[/terminal]
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $command = trim($content);
        if (empty($command)) {
            return 'Error: No command provided.';
        }

        $sandboxId = $this->getSandboxId($context);
        if (!$sandboxId) {
            return 'Error: No running sandbox assigned to this preset.';
        }

        $error = $this->ensureSession($sandboxId, $context);
        if ($error) {
            return $error;
        }

        $output = $this->terminalService->sendCommand(
            $sandboxId,
            $command,
            $context->get('sandbox_user', 'sandbox-user'),
            (int) $context->get('command_timeout', 5),
            (int) $context->get('capture_delay_ms', 800),
            (int) $context->get('capture_lines', 50),
        );

        return $output ?? 'Command sent but could not capture output. Try [terminal screen][/terminal].';
    }

    /**
     * Send input or special keys to the terminal.
     *
     * Formats:
     *   "yes"              → plain text, Enter appended
     *   "C-c"              → special key, no Enter
     *   "Up Up Enter"      → key sequence
     *   "q | Enter"        → text q, then Enter key
     *   ""                 → bare Enter
     *
     * [terminal send]...[/terminal]
     */
    public function send(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $sandboxId = $this->getSandboxId($context);
        if (!$sandboxId) {
            return 'Error: No running sandbox assigned to this preset.';
        }

        $error = $this->ensureSession($sandboxId, $context);
        if ($error) {
            return $error;
        }

        $output = $this->terminalService->sendInput(
            $sandboxId,
            $content,
            $context->get('sandbox_user', 'sandbox-user'),
            (int) $context->get('command_timeout', 5),
            (int) $context->get('capture_delay_ms', 800),
            (int) $context->get('capture_lines', 50),
        );

        return $output ?? 'Input sent. Use [terminal screen][/terminal] to check the result.';
    }

    /**
     * Capture current terminal screen.
     * [terminal screen][/terminal]      → last N lines (from config)
     * [terminal screen]100[/terminal]   → last 100 lines of scrollback
     */
    public function screen(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $sandboxId = $this->getSandboxId($context);
        if (!$sandboxId) {
            return 'Error: No running sandbox assigned to this preset.';
        }

        $lines = trim($content) !== '' && is_numeric(trim($content))
            ? (int) trim($content)
            : (int) $context->get('capture_lines', 50);

        $screen = $this->terminalService->captureScreen(
            $sandboxId,
            $context->get('sandbox_user', 'sandbox-user'),
            (int) $context->get('command_timeout', 5),
            $lines,
        );

        return $screen ?? 'No terminal session running. Use [terminal]any command[/terminal] to start one.';
    }

    /**
     * Turn monitor ON — [[terminal_screen]] injects screen every cycle.
     * [terminal on][/terminal]
     */
    public function on(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, self::META_MONITOR, true);

        return 'Terminal monitor ON — terminal_screen will show the current screen in your prompt every cycle.';
    }

    /**
     * Turn monitor OFF — [[terminal_screen]] resolves to empty string.
     * Terminal session keeps running.
     * [terminal off][/terminal]
     */
    public function off(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, self::META_MONITOR, false);

        return 'Terminal monitor OFF — terminal keeps running. Use [terminal screen][/terminal] to check manually.';
    }

    /**
     * Show terminal session status and monitor state.
     * [terminal status][/terminal]
     */
    public function status(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $sandboxId = $this->getSandboxId($context);
        if (!$sandboxId) {
            return 'Terminal status: no running sandbox assigned to this preset.';
        }

        $user         = $context->get('sandbox_user', 'sandbox-user');
        $timeout      = (int) $context->get('command_timeout', 5);
        $monitorOn    = $this->isMonitorOn($context);
        $sessionAlive = $this->terminalService->sessionExists($sandboxId, $user, $timeout);

        $monitorText = $monitorOn
            ? 'ON (screen injected into prompt every cycle)'
            : 'OFF (use [terminal screen][/terminal] for manual snapshot)';

        return implode("\n", [
            'Terminal status:',
            '  Sandbox:      ' . $sandboxId,
            '  tmux session: ' . ($sessionAlive ? 'running' : 'stopped'),
            '  Monitor:      ' . $monitorText,
        ]);
    }

    /**
     * Kill the current tmux session and start fresh.
     * [terminal reset][/terminal]
     */
    public function reset(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Terminal plugin is disabled.';
        }

        $sandboxId = $this->getSandboxId($context);
        if (!$sandboxId) {
            return 'Error: No running sandbox assigned to this preset.';
        }

        $user    = $context->get('sandbox_user', 'sandbox-user');
        $timeout = (int) $context->get('command_timeout', 5);

        $this->terminalService->killSession($sandboxId, $user, $timeout);
        $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, self::META_INIT, false);

        $error = $this->ensureSession($sandboxId, $context);
        if ($error) {
            return 'Reset failed: ' . $error;
        }

        return 'Terminal session reset — fresh bash session started.';
    }

    // ── Boilerplate ───────────────────────────────────────────────────────────

    public function getSelfClosingTags(): array
    {
        return ['on', 'off', 'status', 'reset', 'screen'];
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function ensureSession(string $sandboxId, PluginExecutionContext $context): ?string
    {
        $user    = $context->get('sandbox_user', 'sandbox-user');
        $timeout = (int) $context->get('command_timeout', 5);

        $initialized = (bool) $this->pluginMetadata->get(
            $context->preset,
            self::PLUGIN_NAME,
            self::META_INIT,
            false
        );

        if ($initialized) {
            if ($this->terminalService->sessionExists($sandboxId, $user, $timeout)) {
                return null;
            }
            $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, self::META_INIT, false);
        }

        $error = $this->terminalService->ensureSession($sandboxId, $user, $timeout);

        if ($error === null) {
            $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, self::META_INIT, true);
        }

        return $error;
    }

    private function getSandboxId(PluginExecutionContext $context): ?string
    {
        $assigned = $this->presetSandboxService->getAssignedSandbox($context->preset->getId());

        if (!$assigned || ($assigned['sandbox']->status ?? '') !== 'running') {
            return null;
        }

        return $assigned['sandbox_id'];
    }

    private function isMonitorOn(PluginExecutionContext $context): bool
    {
        return (bool) $this->pluginMetadata->get(
            $context->preset,
            self::PLUGIN_NAME,
            self::META_MONITOR,
            false
        );
    }
}
