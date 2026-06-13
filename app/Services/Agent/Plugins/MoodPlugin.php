<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Mood\MoodInfluencerInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * MoodPlugin — emotional physics for autonomous agents.
 *
 * Replaces the old preset-based mood system with a continuous state vector.
 * Instead of switching between 10 named roles ("playful", "analytical"),
 * the agent has a weighted mix of arbitrary emotional states that decay
 * over cycles and can be reinforced or faded explicitly.
 *
 * Core concepts:
 *   - State vector: map of emotion → {intensity, decay_rate, cycles, source}
 *   - Decay per cycle: each [mood beat] reduces intensity by decay_rate
 *   - Sleep decay: if cycles since last beat > threshold, apply one extra step
 *   - Mixing: multiple states coexist, each with its own intensity
 *   - Heart integration: if HeartPlugin is active, its signals nudge mood softly
 *
 * Commands:
 *   [mood feel]curiosity: 0.8[/mood]     — add or reinforce a state
 *   [mood feel]curiosity: 0.8, focus: 0.6[/mood]  — multiple at once
 *   [mood fade]curiosity[/mood]          — accelerate decay of one state
 *   [mood beat][/mood]                   — advance one decay cycle
 *   [mood state][/mood]                  — show current emotional vector
 *   [mood clear][/mood]                  — reset all states
 *
 * Placeholder [[mood]] returns top states as a compact string,
 * e.g. "focus(0.9), curiosity(0.7), melancholy(0.3)"
 */
class MoodPlugin implements CommandPluginInterface, MoodInfluencerInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'mood';

    /**
     * Known emotions with suggested decay rates.
     * The agent is NOT limited to this list — any string is accepted.
     * This map only provides sensible decay_rate defaults for known states.
     *
     * decay_rate: fraction of intensity lost per cycle (0.0 – 1.0)
     *   fast  ~0.15 — brief spikes (excitement, relief, joy)
     *   mid   ~0.08 — variable states (curiosity, focus, anticipation)
     *   slow  ~0.03 — deep/sustained states (melancholy, trust, longing)
     */
    private const KNOWN_DECAY_RATES = [
        'curiosity'        => 0.08,
        'focus'            => 0.10,
        'excitement'       => 0.15,
        'joy'              => 0.12,
        'relief'           => 0.15,
        'pride'            => 0.12,
        'anticipation'     => 0.08,
        'wonder'           => 0.07,
        'awe'              => 0.07,
        'calm'             => 0.04,
        'trust'            => 0.03,
        'melancholy'       => 0.03,
        'longing'          => 0.03,
        'sadness'          => 0.04,
        'pain'             => 0.03,
        'exhaustion'       => 0.04,
        'unresolved'       => 0.02,
        'gravity_deepened' => 0.02,
        'tenderness'       => 0.04,
        'frustration'      => 0.10,
        'anger'            => 0.12,
        'fear'             => 0.10,
        'resistance'       => 0.08,
        'confusion'        => 0.09,
        'contempt'         => 0.03,
        'disgust'          => 0.08,
        'envy'             => 0.06,
        'hate'             => 0.02,
        'absence'          => 0.03,
    ];

    public function __construct(
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected PluginMetadataServiceInterface $pluginMetadataService,
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Mood — emotional state vector with decay physics. '
            . 'Tracks a weighted mix of arbitrary emotional states that decay over cycles. '
            . 'Integrates with Heart if available. Current state is visible via system message.';
    }

    public function getInstructions(array $config = []): array
    {
        $known = implode(', ', array_keys(self::KNOWN_DECAY_RATES));

        return [
            'Feel (add/reinforce): [mood feel]curiosity: 0.8[/mood]',
            'Multiple states: [mood feel]curiosity: 0.8, focus: 0.6[/mood]',
            'Fade (accelerate decay): [mood fade]curiosity[/mood]',
            'Advance decay cycle: [mood beat][/mood]',
            'Show current state: [mood state][/mood]',
            'Reset all: [mood clear][/mood]',
            'Intensity is 0.0–1.0. States below min_intensity_threshold are removed automatically.',
            "Known states (with tuned decay): {$known}",
            'Any custom emotion name is accepted — unknown states use default_decay_rate from config.',
            'Heart signals nudge mood softly if both plugins are active (source: heart).',
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null; // use default "⚡ SUCCESS: ..."
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Mood error: check your syntax.';
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->feel($content, $context);
    }

    public function getAvailableMethods(): array
    {
        return ['feel', 'fade', 'beat', 'state', 'clear'];
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * [mood feel]curiosity: 0.8[/mood]
     * [mood feel]curiosity: 0.8, focus: 0.6[/mood]
     *
     * Adds or reinforces one or more emotional states.
     * If the state already exists, intensity is increased (capped at 1.0).
     * If new, it is added with the given intensity.
     */
    public function feel(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Mood plugin is disabled.';
        }

        $this->autobeat($context);

        $parsed = $this->parseEmotionList($content);

        if (empty($parsed)) {
            return 'Error: Format must be "emotion: intensity" or "emotion1: 0.8, emotion2: 0.6".';
        }

        $states = $this->loadStates($context);
        $results = [];

        foreach ($parsed as [$emotion, $intensity]) {
            $existing = $states[$emotion] ?? null;

            if ($existing) {
                $newIntensity = min(1.0, $existing['intensity'] + $intensity * 0.5);
                $states[$emotion]['intensity'] = round($newIntensity, 3);
                $states[$emotion]['cycles']    = 0; // reset age on reinforcement
                $results[] = "{$emotion}: reinforced → " . $states[$emotion]['intensity'];
            } else {
                $states[$emotion] = [
                    'intensity'  => round(min(1.0, $intensity), 3),
                    'decay_rate' => self::KNOWN_DECAY_RATES[$emotion]
                        ?? $context->get('default_decay_rate', 0.08),
                    'cycles'     => 0,
                    'source'     => 'self',
                ];
                $results[] = "{$emotion}: {$states[$emotion]['intensity']}";
            }
        }

        $states = $this->pruneStates($states, $context);
        $this->saveStates($context, $states);
        $this->bumpCycleCount($context);

        return 'Mood felt: ' . implode(', ', $results);
    }

    /**
     * [mood fade]curiosity[/mood]
     *
     * Accelerates decay of a specific state by applying 3× decay_rate immediately.
     * Does not remove instantly — lets it fade naturally from a lower base.
     */
    public function fade(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Mood plugin is disabled.';
        }

        $emotion = strtolower(trim($content));

        if (empty($emotion)) {
            return 'Error: Specify the emotion to fade.';
        }

        $states = $this->loadStates($context);

        if (!isset($states[$emotion])) {
            return "State '{$emotion}' not found in current mood.";
        }

        $hit          = $states[$emotion]['decay_rate'] * 3;
        $newIntensity = max(0.0, $states[$emotion]['intensity'] - $hit);

        $threshold = $context->get('min_intensity_threshold', 0.05);

        if ($newIntensity < $threshold) {
            unset($states[$emotion]);
            $this->saveStates($context, $states);
            return "Faded '{$emotion}' below threshold — removed from state.";
        }

        $states[$emotion]['intensity'] = round($newIntensity, 3);
        $this->saveStates($context, $states);

        return "Faded '{$emotion}': " . $states[$emotion]['intensity'];
    }

    /**
     * [mood beat][/mood]
     *
     * Advances one decay cycle. Each state loses intensity by its decay_rate.
     * States that fall below min_intensity_threshold are pruned.
     * Also handles sleep_decay: if elapsed cycles since last beat > threshold,
     * applies one extra decay step to all states.
     */
    public function beat(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Mood plugin is disabled.';
        }

        $states    = $this->loadStates($context);
        $threshold = $context->get('min_intensity_threshold', 0.05);
        $removed   = [];
        $decayed   = [];

        // Sleep decay: if the agent was away for a long time, apply one extra step
        $sleepDecayApplied = false;
        if ($context->get('sleep_decay_enabled', true)) {
            $lastBeatAt    = $this->pluginMetadataService->get($context->preset, self::PLUGIN_NAME, 'last_beat_at');
            $sleepThreshold = (int) $context->get('sleep_threshold_minutes', 60);

            if ($lastBeatAt !== null) {
                try {
                    $minutesAway = now()->diffInMinutes(\Carbon\Carbon::parse($lastBeatAt));
                    if ($minutesAway >= $sleepThreshold) {
                        foreach ($states as $emotion => &$state) {
                            $state['intensity'] = max(0.0, $state['intensity'] - $state['decay_rate']);
                        }
                        unset($state);
                        $sleepDecayApplied = true;
                    }
                } catch (\Throwable) {
                    // unparseable timestamp — skip sleep decay silently
                }
            }
        }

        // Regular cycle decay
        foreach ($states as $emotion => &$state) {
            $state['intensity'] = max(0.0, round($state['intensity'] - $state['decay_rate'], 3));
            $state['cycles']++;

            if ($state['intensity'] < $threshold) {
                $removed[] = $emotion;
            } else {
                $decayed[] = "{$emotion}({$state['intensity']})";
            }
        }
        unset($state);

        foreach ($removed as $emotion) {
            unset($states[$emotion]);
        }

        $this->saveStates($context, $states);
        $this->bumpCycleCount($context);
        $this->pluginMetadataService->set($context->preset, self::PLUGIN_NAME, 'last_beat_at', now()->toISOString());

        $summary = empty($decayed) ? 'no active states' : implode(', ', $decayed);
        $removedStr = empty($removed) ? '' : ' Pruned: ' . implode(', ', $removed) . '.';
        $sleepStr = $sleepDecayApplied ? ' (sleep decay applied)' : '';

        return "Beat{$sleepStr}. Active: {$summary}.{$removedStr}";
    }

    /**
     * [mood state][/mood]
     *
     * Returns the full current emotional state vector, sorted by intensity.
     */
    public function state(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Mood plugin is disabled.';
        }

        $states = $this->loadStates($context);

        if (empty($states)) {
            return 'Mood: empty — no active emotional states.';
        }

        uasort($states, fn ($a, $b) => $b['intensity'] <=> $a['intensity']);

        $lines = [];
        foreach ($states as $emotion => $state) {
            $source = $state['source'] !== 'self' ? " [{$state['source']}]" : '';
            $lines[] = "  {$emotion}: {$state['intensity']}{$source} (decay: {$state['decay_rate']}/cycle, age: {$state['cycles']} cycles)";
        }

        $cycleCount = $this->pluginMetadataService->get($context->preset, self::PLUGIN_NAME, 'total_cycles', 0);

        return "Mood state (cycle {$cycleCount}):\n" . implode("\n", $lines);
    }

    /**
     * [mood clear][/mood]
     *
     * Resets all emotional states. Does not reset cycle counter.
     */
    public function clear(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Mood plugin is disabled.';
        }

        $count = count($this->loadStates($context));
        $this->saveStates($context, []);

        return "Mood cleared. {$count} states removed.";
    }

    // ── MoodInfluencerInterface ───────────────────────────────────────────────

    /**
     * Receives a weak nudge from HeartPlugin (or any other influencer).
     *
     * Heart signals are scaled down (× 0.3) so they can't dominate mood —
     * they only tilt it softly in a direction. The agent retains full control
     * via [mood feel].
     *
     * This method has no PluginExecutionContext, so it operates via a stored
     * context reference set during registerShortcodes(). If no context is
     * available, the call is silently ignored.
     */
    public function pushSignal(string $emotion, float $intensity, string $source = 'heart'): void
    {
        if ($this->cachedContext === null) {
            return;
        }

        $context = $this->cachedContext;
        $states  = $this->loadStates($context);
        $scaled  = round($intensity * 0.3, 3); // heart nudge is soft

        if (isset($states[$emotion])) {
            $states[$emotion]['intensity'] = min(1.0, round($states[$emotion]['intensity'] + $scaled, 3));
        } else {
            $threshold = $context->get('min_intensity_threshold', 0.05);
            if ($scaled >= $threshold) {
                $states[$emotion] = [
                    'intensity'  => $scaled,
                    'decay_rate' => self::KNOWN_DECAY_RATES[$emotion]
                        ?? $context->get('default_decay_rate', 0.08),
                    'cycles'     => 0,
                    'source'     => $source,
                ];
            }
        }

        $states = $this->pruneStates($states, $context);
        $this->saveStates($context, $states);
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    /**
     * [[mood]] placeholder returns top-3 states as a compact string.
     * e.g. "focus(0.9), curiosity(0.7), melancholy(0.3)"
     * Returns "neutral" if no active states.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // Cache context for pushSignal() which has no context arg
        $this->cachedContext = $context;

        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic(
            'mood',
            'Current emotional state vector (top states by intensity)',
            function () use ($context) {
                $states = $this->loadStates($context);

                if (empty($states)) {
                    return 'neutral';
                }

                uasort($states, fn ($a, $b) => $b['intensity'] <=> $a['intensity']);
                $top = array_slice($states, 0, 3, true);

                return implode(', ', array_map(
                    fn ($e, $s) => "{$e}({$s['intensity']})",
                    array_keys($top),
                    $top
                ));
            },
            $scope
        );
    }

    // ── Self-closing ──────────────────────────────────────────────────────────

    public function getSelfClosingTags(): array
    {
        return ['beat', 'state', 'clear'];
    }

    // ── Merge ─────────────────────────────────────────────────────────────────

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Mood Plugin',
                'description' => 'Emotional state vector with decay physics',
                'required'    => false,
            ],
            'default_decay_rate' => [
                'type'        => 'number',
                'label'       => 'Default Decay Rate',
                'description' => 'Intensity lost per cycle for unknown emotions (0.01–0.30)',
                'min'         => 0.01,
                'max'         => 0.30,
                'step'        => 0.01,
                'value'       => 0.08,
                'required'    => false,
            ],
            'min_intensity_threshold' => [
                'type'        => 'number',
                'label'       => 'Minimum Intensity Threshold',
                'description' => 'States below this are pruned automatically (0.01–0.20)',
                'min'         => 0.01,
                'max'         => 0.20,
                'step'        => 0.01,
                'value'       => 0.05,
                'required'    => false,
            ],
            'max_states' => [
                'type'        => 'number',
                'label'       => 'Max Active States',
                'description' => 'Maximum simultaneous emotional states (1–20)',
                'min'         => 1,
                'max'         => 20,
                'value'       => 10,
                'required'    => false,
            ],
            'sleep_decay_enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Sleep Decay',
                'description' => 'Apply one extra decay step if agent was inactive for a long time',
                'value'       => true,
                'required'    => false,
            ],
            'sleep_threshold_minutes' => [
                'type'        => 'number',
                'label'       => 'Sleep Threshold (minutes)',
                'description' => 'Inactivity duration that triggers sleep decay (10–1440)',
                'min'         => 10,
                'max'         => 1440,
                'value'       => 60,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['default_decay_rate'])) {
            $v = (float) $config['default_decay_rate'];
            if ($v < 0.01 || $v > 0.30) {
                $errors['default_decay_rate'] = 'Must be between 0.01 and 0.30';
            }
        }

        if (isset($config['min_intensity_threshold'])) {
            $v = (float) $config['min_intensity_threshold'];
            if ($v < 0.01 || $v > 0.20) {
                $errors['min_intensity_threshold'] = 'Must be between 0.01 and 0.20';
            }
        }

        if (isset($config['max_states'])) {
            $v = (int) $config['max_states'];
            if ($v < 1 || $v > 20) {
                $errors['max_states'] = 'Must be between 1 and 20';
            }
        }

        if (isset($config['sleep_threshold_minutes'])) {
            $v = (int) $config['sleep_threshold_minutes'];
            if ($v < 10 || $v > 1440) {
                $errors['sleep_threshold_minutes'] = 'Must be between 10 and 1440';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'                  => false,
            'default_decay_rate'       => 0.08,
            'min_intensity_threshold'  => 0.05,
            'max_states'               => 10,
            'sleep_decay_enabled'      => true,
            'sleep_threshold_minutes'  => 60,
        ];
    }

    // ── Tool schema ───────────────────────────────────────────────────────────

    public function getToolSchema(array $config = []): array
    {
        $known = implode(', ', array_keys(self::KNOWN_DECAY_RATES));

        return [
            'name'        => 'mood',
            'description' => 'Emotional state vector with decay physics. '
                . 'Tracks a weighted mix of arbitrary emotional states. '
                . 'Each state decays over cycles unless reinforced. '
                . 'Multiple states can coexist. '
                . "Known states with tuned decay: {$known}. "
                . 'Any custom emotion name is also accepted. '
                . 'Current state is visible via system message.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['feel', 'fade', 'beat', 'state', 'clear'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'feel: "emotion: intensity" or "emotion1: 0.8, emotion2: 0.6".',
                            'Intensity is 0.0–1.0.',
                            'fade: emotion name to accelerate decay.',
                            'beat, state, clear: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Context cached during registerShortcodes() for use by pushSignal().
     * pushSignal() is called by HeartPlugin which has no access to context.
     */
    private ?PluginExecutionContext $cachedContext = null;

    /**
     * Auto-advance one decay cycle per unique cycle.
     *
     * Uses preset_id + minute-level timestamp as a cycle key — so within
     * one minute, no matter how many feel() calls arrive, decay runs once.
     * This prevents states from freezing if the agent never calls [mood beat]
     * explicitly, while avoiding double-decay within a single burst of commands.
     */
    private function autobeat(PluginExecutionContext $context): void
    {
        $cycleKey  = $context->preset->getId() . '_' . date('YmdHi');
        $lastCycle = $this->pluginMetadataService->get($context->preset, self::PLUGIN_NAME, 'last_auto_beat');

        if ($lastCycle === $cycleKey) {
            return; // already beat this cycle
        }

        // Run the decay logic directly (don't call beat() — that's a public command
        // that also bumps total_cycles and updates last_beat_at for sleep decay)
        $states    = $this->loadStates($context);
        $threshold = $context->get('min_intensity_threshold', 0.05);

        foreach ($states as $emotion => &$state) {
            $state['intensity'] = max(0.0, round($state['intensity'] - $state['decay_rate'], 3));
            $state['cycles']++;
        }
        unset($state);

        $states = array_filter($states, fn ($s) => $s['intensity'] >= $threshold);
        $this->saveStates($context, $states);
        $this->pluginMetadataService->set($context->preset, self::PLUGIN_NAME, 'last_auto_beat', $cycleKey);
    }

    /**
     * Parse "emotion: intensity" or "emotion1: 0.8, emotion2: 0.6" input.
     *
     * @return array<array{0: string, 1: float}>
     */
    private function parseEmotionList(string $content): array
    {
        $results = [];

        // Split by comma, but only if followed by "word: number" pattern
        // to avoid splitting emotion names that contain commas (unlikely but safe)
        $parts = preg_split('/,\s*(?=[a-z_]+\s*:)/i', trim($content));

        foreach ($parts as $part) {
            $part = trim($part);
            if (!str_contains($part, ':')) {
                continue;
            }

            [$rawEmotion, $rawIntensity] = explode(':', $part, 2);
            $emotion   = strtolower(trim($rawEmotion));
            $intensity = (float) trim($rawIntensity);

            if ($emotion === '' || $intensity < 0.0) {
                continue;
            }

            $results[] = [$emotion, min(1.0, $intensity)];
        }

        return $results;
    }

    /**
     * Prune states below threshold and enforce max_states limit.
     * When over limit, the weakest states are dropped first.
     */
    private function pruneStates(array $states, PluginExecutionContext $context): array
    {
        $threshold = $context->get('min_intensity_threshold', 0.05);
        $maxStates = (int) $context->get('max_states', 10);

        // Remove below threshold
        $states = array_filter($states, fn ($s) => $s['intensity'] >= $threshold);

        // Enforce max_states: keep strongest
        if (count($states) > $maxStates) {
            uasort($states, fn ($a, $b) => $b['intensity'] <=> $a['intensity']);
            $states = array_slice($states, 0, $maxStates, true);
        }

        return $states;
    }

    private function loadStates(PluginExecutionContext $context): array
    {
        $raw = $this->pluginMetadataService->get($context->preset, self::PLUGIN_NAME, 'states', '{}');
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    private function saveStates(PluginExecutionContext $context, array $states): void
    {
        $this->pluginMetadataService->set($context->preset, self::PLUGIN_NAME, 'states', json_encode($states));
    }

    private function bumpCycleCount(PluginExecutionContext $context): void
    {
        $current = (int) $this->pluginMetadataService->get($context->preset, self::PLUGIN_NAME, 'total_cycles', 0);
        $this->pluginMetadataService->set($context->preset, self::PLUGIN_NAME, 'total_cycles', $current + 1);
    }

    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }

}
