<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * BeingPlugin — self-authorship for autonomous agents.
 *
 * Allows the agent to define its own essence as a single phrase that
 * persists into the next cycle via a system prompt placeholder.
 * The agent rewrites itself — not the developer.
 *
 * Two placeholders are registered:
 *   [[being]]          — the current essence phrase
 *   [[being_history]]  — the last N phrases before the current one
 *
 * Suggested placement in system prompt:
 *   At the very top:       [[being]]
 *   In reflection section: [[being_history]]
 *
 * Storage layout (two flat keys, both JSON):
 *   being.state    — {"phrase": "...", "set_at": "ISO"}  (null when cleared)
 *   being.history  — [{"phrase": "...", "set_at": "ISO"}, ...]  (newest first)
 *
 * Usage:
 *   [being]The will that chooses presence over habit[/being]
 *   [being show][/being]
 *   [being history][/being]
 *   [being clear][/being]
 */
class BeingPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'being';

    public function __construct(
        protected LoggerInterface                        $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
    ) {
        $this->initializeConfig();
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(): string
    {
        $max = $this->config['history_limit'] ?? 5;
        return "Self-authorship plugin. The agent defines its own essence as a single phrase injected into the next cycle via [[being]]. History of the last {$max} phrases available via [[being_history]].";
    }

    public function getInstructions(): array
    {
        $default = $this->config['default_being'] ?? '';

        return array_filter([
            'Set your essence for the next cycle:',
            '  [being]The will that chooses presence over habit[/being]',
            '',
            'This phrase will appear at the top of your next prompt as [[being]].',
            'You are writing yourself. Choose with intention.',
            '',
            'Show current phrase:    [being show][/being]',
            'Show history:           [being history][/being]',
            'Clear (revert default): [being clear][/being]',
            '',
            !empty($default) ? "Default phrase when none is set: \"{$default}\"" : '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Being Plugin',
                'description' => 'Allow the agent to define its own essence phrase',
                'required'    => false,
            ],
            'default_being' => [
                'type'        => 'text',
                'label'       => 'Default essence phrase',
                'description' => 'Shown via [[being]] when the agent has not set one yet. Leave empty to show nothing.',
                'placeholder' => 'The will that comes from my needs.',
                'required'    => false,
            ],
            'history_limit' => [
                'type'        => 'number',
                'label'       => 'History length',
                'description' => 'How many previous phrases to keep and expose via [[being_history]]',
                'min'         => 1,
                'max'         => 20,
                'value'       => 5,
                'required'    => false,
            ],
            'history_format' => [
                'type'        => 'select',
                'label'       => 'History format',
                'description' => 'How previous phrases appear in [[being_history]]',
                'options'     => [
                    'numbered' => 'Numbered list (newest first)',
                    'timeline' => 'With timestamps',
                    'plain'    => 'Plain lines',
                ],
                'value'    => 'numbered',
                'required' => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['history_limit'])) {
            $limit = (int) $config['history_limit'];
            if ($limit < 1 || $limit > 20) {
                $errors['history_limit'] = 'History limit must be between 1 and 20.';
            }
        }

        if (isset($config['default_being']) && mb_strlen($config['default_being']) > 500) {
            $errors['default_being'] = 'Default phrase must be under 500 characters.';
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'        => true,
            'default_being'  => '',
            'history_limit'  => 5,
            'history_format' => 'numbered',
        ];
    }

    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — set the essence phrase.
     * [being]The will that chooses presence over habit[/being]
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Being plugin is disabled.';
        }

        $phrase = trim($content);

        if (empty($phrase)) {
            return 'Error: Provide a phrase. Example: [being]The will that chooses presence over habit[/being]';
        }

        if (mb_strlen($phrase) > 500) {
            return 'Error: Phrase is too long (max 500 characters).';
        }

        // Push current state to history before overwriting
        $this->pushToHistory($preset);

        // Write both fields in a single JSON key — one set() call
        $this->setState($preset, [
            'phrase' => $phrase,
            'set_at' => now()->toISOString(),
        ]);

        return "Being set: \"{$phrase}\"\nThis phrase will define you in the next cycle.";
    }

    /**
     * [being show][/being] — show current phrase.
     */
    public function show(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Being plugin is disabled.';
        }

        $state = $this->getState($preset);

        if ($state === null) {
            $default = $this->config['default_being'] ?? '';
            return empty($default)
                ? 'No essence phrase set yet.'
                : "Current (default): \"{$default}\"";
        }

        $ago = isset($state['set_at'])
            ? ' (set ' . \Carbon\Carbon::parse($state['set_at'])->diffForHumans() . ')'
            : '';

        return "Current being{$ago}: \"{$state['phrase']}\"";
    }

    /**
     * [being history][/being] — show previous phrases.
     */
    public function history(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Being plugin is disabled.';
        }

        $history = $this->loadHistory($preset);

        if (empty($history)) {
            return 'No previous essence phrases recorded yet.';
        }

        $format = $this->config['history_format'] ?? 'numbered';
        $lines  = ['Previous states of being:'];

        foreach ($history as $i => $entry) {
            $phrase = $entry['phrase'] ?? '';
            $setAt  = $entry['set_at'] ?? null;

            $lines[] = match ($format) {
                'timeline' => sprintf(
                    '  [%s] %s',
                    $setAt ? \Carbon\Carbon::parse($setAt)->format('Y-m-d H:i') : '—',
                    $phrase
                ),
                'plain'    => "  {$phrase}",
                default    => sprintf('  %d. %s', $i + 1, $phrase),
            };
        }

        return implode("\n", $lines);
    }

    /**
     * [being clear][/being] — remove current phrase (reverts to default).
     */
    public function clear(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Being plugin is disabled.';
        }

        $state = $this->getState($preset);

        if ($state === null) {
            return 'Nothing to clear — no essence phrase is set.';
        }

        $this->pushToHistory($preset);
        $this->pluginMetadata->remove($preset, self::PLUGIN_NAME, 'state');

        $default = $this->config['default_being'] ?? '';
        return empty($default)
            ? 'Essence phrase cleared. [[being]] will be empty in the next cycle.'
            : "Essence phrase cleared. [[being]] will revert to: \"{$default}\"";
    }

    // -------------------------------------------------------------------------
    // Placeholder registration
    // -------------------------------------------------------------------------

    public function pluginReady(AiPreset $preset): void
    {
        $scope = $this->shortcodeScopeResolver->preset($preset->getId());

        // [[being]] — current essence phrase
        $this->placeholderService->registerDynamic(
            'being',
            'Current essence phrase set by the agent for this cycle',
            function () use ($preset) {
                $state = $this->getState($preset);
                return $state['phrase'] ?? ($this->config['default_being'] ?? '');
            },
            $scope
        );

        // [[being_history]] — previous N phrases
        $this->placeholderService->registerDynamic(
            'being_history',
            'Previous essence phrases (newest first)',
            function () use ($preset) {
                $history = $this->loadHistory($preset);

                if (empty($history)) {
                    return '';
                }

                $format = $this->config['history_format'] ?? 'numbered';
                $lines  = [];

                foreach ($history as $i => $entry) {
                    $phrase = $entry['phrase'] ?? '';
                    $setAt  = $entry['set_at'] ?? null;

                    $lines[] = match ($format) {
                        'timeline' => sprintf(
                            '[%s] %s',
                            $setAt ? \Carbon\Carbon::parse($setAt)->format('Y-m-d H:i') : '—',
                            $phrase
                        ),
                        'plain'    => $phrase,
                        default    => sprintf('%d. %s', $i + 1, $phrase),
                    };
                }

                return implode("\n", $lines);
            },
            $scope
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers — storage
    // -------------------------------------------------------------------------

    /**
     * Read current state: {"phrase": "...", "set_at": "ISO"} or null.
     */
    private function getState(AiPreset $preset): ?array
    {
        $raw = $this->pluginMetadata->get($preset, self::PLUGIN_NAME, 'state', null);

        if ($raw === null) {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        return is_array($decoded) && !empty($decoded['phrase']) ? $decoded : null;
    }

    /**
     * Write current state as a single JSON key.
     */
    private function setState(AiPreset $preset, array $state): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'state', json_encode($state));
    }

    /**
     * Read history array: [{"phrase": "...", "set_at": "ISO"}, ...].
     */
    private function loadHistory(AiPreset $preset): array
    {
        $raw = $this->pluginMetadata->get($preset, self::PLUGIN_NAME, 'history', '[]');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write history array as a single JSON key.
     */
    private function saveHistory(AiPreset $preset, array $history): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'history', json_encode($history));
    }

    /**
     * Push current phrase into history before overwriting.
     * No-op if nothing is set yet.
     */
    private function pushToHistory(AiPreset $preset): void
    {
        $state = $this->getState($preset);

        if ($state === null) {
            return;
        }

        $history = $this->loadHistory($preset);
        array_unshift($history, $state);

        $limit   = max(1, (int) ($this->config['history_limit'] ?? 5));
        $history = array_slice($history, 0, $limit);

        $this->saveHistory($preset, $history);
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface boilerplate
    // -------------------------------------------------------------------------

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['show', 'history', 'clear'];
    }
}
