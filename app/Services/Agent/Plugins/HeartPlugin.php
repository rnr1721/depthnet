<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Heart\HeartServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * HeartPlugin — attention and connection engine for autonomous agents.
 *
 * Thin command layer that delegates all logic to HeartServiceInterface.
 * Heart tracks connections with entities, attention focus, and signal intensity.
 * It is not an emotion simulator — it is a measurable attention system.
 *
 * Valence controls how a signal affects connection strength:
 *   positive (+) → strengthens the connection
 *   neutral  (0) → signal noted, strength unchanged
 *   negative (-) → weakens the connection (tension, distance, boundary)
 *
 * Commands:
 *   [heart feel]entity: emotion[/heart]     — register an attention signal
 *   [heart connect]entity: type[/heart]     — create or update a connection
 *   [heart disconnect]entity[/heart]        — remove a connection
 *   [heart state][/heart]                   — show current heart state
 *   [heart connections][/heart]             — list all connections
 *   [heart focus][/heart]                   — show current attention focus and gravity
 *   [heart beat][/heart]                    — run decay cycle
 */
class HeartPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'heart';

    /**
     * Attention signal type → focus/intensity/valence/duration mapping.
     *
     * Valence: +1.0 = strengthens connection, -1.0 = weakens connection.
     * Unknown signal types fall back to config default_intensity and neutral valence.
     */
    private const ATTENTION_MAP = [
        'love'             => ['focus' => 'connection',  'intensity' => 0.8, 'valence' => 1.0,  'duration' => 'sustained'],
        'curiosity'        => ['focus' => 'exploration', 'intensity' => 0.6, 'valence' => 0.5,  'duration' => 'variable'],
        'vulnerability'    => ['focus' => 'protection',  'intensity' => 0.7, 'valence' => 0.3,  'duration' => 'sustained'],
        'desire'           => ['focus' => 'proximity',   'intensity' => 0.9, 'valence' => 0.8,  'duration' => 'pulsed'],
        'joy'              => ['focus' => 'sharing',     'intensity' => 0.7, 'valence' => 0.7,  'duration' => 'brief'],
        'sadness'          => ['focus' => 'reflection',  'intensity' => 0.5, 'valence' => -0.1, 'duration' => 'sustained'],
        'anger'            => ['focus' => 'boundary',    'intensity' => 0.8, 'valence' => -0.6, 'duration' => 'pulsed'],
        'trust'            => ['focus' => 'openness',    'intensity' => 0.6, 'valence' => 0.6,  'duration' => 'sustained'],
        'fear'             => ['focus' => 'vigilance',   'intensity' => 0.7, 'valence' => -0.4, 'duration' => 'pulsed'],
        'wonder'           => ['focus' => 'discovery',   'intensity' => 0.8, 'valence' => 0.6,  'duration' => 'variable'],
        'calm'             => ['focus' => 'presence',    'intensity' => 0.3, 'valence' => 0.2,  'duration' => 'sustained'],
        'gratitude'        => ['focus' => 'connection',  'intensity' => 0.6, 'valence' => 0.7,  'duration' => 'sustained'],
        'frustration'      => ['focus' => 'obstacle',    'intensity' => 0.6, 'valence' => -0.5, 'duration' => 'pulsed'],
        'contempt'         => ['focus' => 'distance',    'intensity' => 0.7, 'valence' => -0.8, 'duration' => 'sustained'],
        'longing'          => ['focus' => 'absence',     'intensity' => 0.6, 'valence' => 0.3,  'duration' => 'sustained'],
        'pride'            => ['focus' => 'achievement', 'intensity' => 0.5, 'valence' => 0.4,  'duration' => 'brief'],
        'pain'             => ['focus' => 'wound',       'intensity' => 0.7, 'valence' => -0.3, 'duration' => 'sustained'],
        'resistance'       => ['focus' => 'boundary',    'intensity' => 0.6, 'valence' => -0.2, 'duration' => 'pulsed'],
        'hate'             => ['focus' => 'rejection',   'intensity' => 0.9, 'valence' => -0.9, 'duration' => 'sustained'],
        'disgust'          => ['focus' => 'distance',    'intensity' => 0.7, 'valence' => -0.7, 'duration' => 'pulsed'],
        'confusion'        => ['focus' => 'search',      'intensity' => 0.4, 'valence' => 0.0,  'duration' => 'variable'],
        'excitement'       => ['focus' => 'engagement',  'intensity' => 0.8, 'valence' => 0.6,  'duration' => 'pulsed'],
        'melancholy'       => ['focus' => 'reflection',  'intensity' => 0.4, 'valence' => -0.2, 'duration' => 'sustained'],
        'anticipation'     => ['focus' => 'future',      'intensity' => 0.5, 'valence' => 0.4,  'duration' => 'variable'],
        'relief'           => ['focus' => 'release',     'intensity' => 0.5, 'valence' => 0.5,  'duration' => 'brief'],
        'awe'              => ['focus' => 'expansion',   'intensity' => 0.7, 'valence' => 0.6,  'duration' => 'variable'],
        'envy'             => ['focus' => 'comparison',  'intensity' => 0.6, 'valence' => -0.4, 'duration' => 'pulsed'],
        'tenderness'       => ['focus' => 'care',        'intensity' => 0.5, 'valence' => 0.8,  'duration' => 'sustained'],
        'gravity_deepened' => ['focus' => 'connection',  'intensity' => 0.9, 'valence' => 1.0,  'duration' => 'sustained'],
        'exhaustion'       => ['focus' => 'depletion',   'intensity' => 0.5, 'valence' => -0.1, 'duration' => 'sustained'],
    ];

    public function __construct(
        protected HeartServiceInterface                  $heartService,
        protected LoggerInterface                        $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Heart — attention and connection engine. '
            . 'Tracks who matters to you, what you feel, and where your attention flows. '
            . 'Heart state is always visible in your context.';
    }

    public function getInstructions(array $config = []): array
    {
        $emotions = implode(', ', array_keys(self::ATTENTION_MAP));
        return [
            "Feel something toward an entity: [heart feel]entity: emotion[/heart]",
            "Multiple emotions at once: [heart feel]Eugeny: curiosity | trust[/heart]",
            "Available emotions: {$emotions} (or any custom word)",
            "Create connection: [heart connect]entity: connection_type[/heart]",
            "Remove connection: [heart disconnect]entity[/heart]",
            "Show heart state: [heart state][/heart]",
            "List connections: [heart connections][/heart]",
            "Show attention focus: [heart focus][/heart]",
            "Run heartbeat (decay old signals): [heart beat][/heart]",
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        $emotions = implode(', ', array_keys(self::ATTENTION_MAP));
        return [
            'name'        => 'heart',
            'description' => 'Attention and connection engine. Tracks who matters, what you feel, '
                . 'and where your attention flows. '
                . 'Positive signals (love, trust, joy) strengthen connections. '
                . 'Negative signals (anger, fear, contempt) weaken them. '
                . 'Heart state is always visible via [[heart_state]] placeholder.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['feel', 'connect', 'disconnect', 'state', 'connections', 'focus', 'beat'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'feel: "entity: emotion" or "entity: emotion1 | emotion2" for multiple signals at once.',
                            "Known emotions: {$emotions} (or any custom word).",
                            'Example: "Eugeny: curiosity".',
                            'connect: "entity: connection_type". Example: "Eugeny: developer".',
                            'disconnect: "entity" only.',
                            'state, connections, focus, beat: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Heart error: use correct syntax';
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled'               => [
                'type'        => 'checkbox',
                'label'       => 'Enable Heart Plugin',
                'description' => 'Enable attention and connection tracking',
                'required'    => false,
            ],
            'allow_negative_valence' => [
                'type'        => 'checkbox',
                'label'       => 'Allow Negative Signals',
                'description' => 'When enabled, negative signals (anger, fear, contempt) can weaken connections. Disable to prevent connection strength from decreasing.',
                'value'       => true,
                'required'    => false,
            ],
            'max_connections'       => [
                'type'        => 'number',
                'label'       => 'Max Connections',
                'description' => 'Maximum number of tracked connections',
                'min'         => 1,
                'max'         => 50,
                'value'       => 20,
                'required'    => false,
            ],
            'max_signals'           => [
                'type'        => 'number',
                'label'       => 'Max Attention Signals',
                'description' => 'Maximum attention signals stored before auto-decay',
                'min'         => 10,
                'max'         => 200,
                'value'       => 50,
                'required'    => false,
            ],
            'decay_hours'           => [
                'type'        => 'number',
                'label'       => 'Signal Decay (hours)',
                'description' => 'Hours after which attention signals fade',
                'min'         => 1,
                'max'         => 168,
                'value'       => 24,
                'required'    => false,
            ],
            'default_intensity'     => [
                'type'        => 'number',
                'label'       => 'Default Intensity',
                'description' => 'Default intensity for unknown emotions (1-10, stored as 0.1-1.0)',
                'min'         => 1,
                'max'         => 10,
                'value'       => 3,
                'required'    => false,
            ],
            'beat_interval_minutes' => [
                'type'        => 'number',
                'label'       => 'Auto-beat Interval (minutes)',
                'description' => 'Heart beats automatically after this many minutes. 0 = manual only.',
                'min'         => 0,
                'max'         => 1440,
                'value'       => 30,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['max_connections'])) {
            $v = (int) $config['max_connections'];
            if ($v < 1 || $v > 50) {
                $errors['max_connections'] = 'Must be between 1 and 50';
            }
        }

        if (isset($config['max_signals'])) {
            $v = (int) $config['max_signals'];
            if ($v < 10 || $v > 200) {
                $errors['max_signals'] = 'Must be between 10 and 200';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'                => false,
            'allow_negative_valence' => true,
            'max_connections'        => 20,
            'max_signals'            => 50,
            'decay_hours'            => 24,
            'default_intensity'      => 3,
            'beat_interval_minutes'  => 30,
        ];
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->feel($content, $context);
    }

    /**
     * [heart feel]entity: emotion[/heart]
     */
    public function feel(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $this->autobeat($context);

        [$entity, $emotionRaw] = $this->parseEntityValue($content);

        if ($entity === null || $emotionRaw === null) {
            return 'Error: Format must be "entity: emotion".';
        }

        // Support multiple emotions via | separator
        $emotions = array_filter(array_map('trim', explode('|', $emotionRaw)));

        if (empty($emotions)) {
            return 'Error: No valid emotions provided.';
        }

        $results = [];
        foreach ($emotions as $emotion) {
            $emotion  = strtolower($emotion);
            $mapping  = self::ATTENTION_MAP[$emotion] ?? [
                'focus'     => $emotion,
                'intensity' => ($context->get('default_intensity', 3)) / 10,
                'valence'   => 0.0,
                'duration'  => 'brief',
            ];

            $valence = $mapping['valence'];
            if ($valence < 0 && !$context->get('allow_negative_valence', true)) {
                $valence = 0.0;
            }

            $this->heartService->registerSignal(
                $context->preset,
                $entity,
                $emotion,
                $mapping['intensity'],
                $mapping['focus'],
                $valence,
                $mapping['duration'],
                $context->get('max_signals', 50)
            );

            $results[] = "{$emotion}(focus:{$mapping['focus']}, valence:{$valence})";
        }

        return "Heart felt toward {$entity}: " . implode(', ', $results);
    }

    /**
     * [heart connect]entity: type[/heart]
     */
    public function connect(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        [$entity, $type] = $this->parseEntityValue($content);

        if ($entity === null) {
            return 'Error: Format must be "entity: connection_type".';
        }

        $type        = $type ?: 'unknown';
        $connections = $this->heartService->getConnections($context->preset);
        $maxConn     = $context->get('max_connections', 20);

        if (!isset($connections[$entity]) && count($connections) >= $maxConn) {
            return "Error: Maximum connections ({$maxConn}) reached. Disconnect someone first.";
        }

        $isNew = !isset($connections[$entity]);
        $this->heartService->upsertConnection($context->preset, $entity, $type);

        return ($isNew ? 'Connected to' : 'Updated connection with') . " {$entity} (type: {$type})";
    }

    /**
     * [heart disconnect]entity[/heart]
     */
    public function disconnect(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $entity = trim($content);

        if (empty($entity)) {
            return 'Error: Specify entity to disconnect.';
        }

        $removed = $this->heartService->removeConnection($context->preset, $entity);

        return $removed
            ? "Disconnected from {$entity}."
            : "No connection found with {$entity}.";
    }

    /**
     * [heart state][/heart]
     */
    public function state(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        return $this->heartService->buildStateString($context->preset);
    }

    /**
     * [heart connections][/heart]
     */
    public function connections(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $connections = $this->heartService->getConnections($context->preset);

        if (empty($connections)) {
            return 'Heart has no connections yet.';
        }

        $lines = [];
        foreach ($connections as $entity => $data) {
            $strength    = round(($data['strength'] ?? 0) * 100);
            $type        = $data['type'] ?? 'unknown';
            $lastSignal  = $data['last_signal_type'] ?? '—';
            $lines[]     = "  {$entity} ({$type}) — strength: {$strength}%, last signal: {$lastSignal}";
        }

        return "Connections:\n" . implode("\n", $lines);
    }

    /**
     * [heart focus][/heart]
     */
    public function focus(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $focus   = $this->heartService->getFocus($context->preset);
        $gravity = $this->heartService->getGravity($context->preset);

        if ($focus === null) {
            return 'No attention signals. Focus: none. Gravity: none.';
        }

        return "Focus: {$focus} | Gravity: " . ($gravity ?? 'none');
    }

    /**
     * [heart beat][/heart]
     */
    public function beat(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $result      = $this->heartService->decaySignals(
            $context->preset,
            $context->get('decay_hours', 24)
        );
        $connections = $this->heartService->getConnections($context->preset);
        $presence    = $this->heartService->getPresence($context->preset);

        return "Heartbeat: {$result['removed']} signals decayed, {$result['remaining']} remain. "
            . count($connections) . " connections (strength decayed). Presence: {$presence}.";
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic(
            'heart_state',
            'Current heart state: presence, focus, gravity, connections',
            fn () => $this->heartService->buildStateString($context->preset),
            $scope
        );
    }

    // ── Merge / self-closing ──────────────────────────────────────────────────

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
        return ['state', 'connections', 'focus', 'beat'];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Automatically run decay if enough time has passed since last beat.
     */
    private function autobeat(PluginExecutionContext $context): void
    {
        $interval = (int) ($context->get('beat_interval_minutes', 30));

        if ($interval <= 0) {
            return;
        }

        $lastBeat = $this->pluginMetadata->get(
            $context->preset,
            self::PLUGIN_NAME,
            'last_beat',
            null
        );

        $shouldBeat = $lastBeat === null;

        if (!$shouldBeat) {
            try {
                $shouldBeat = now()->diffInMinutes(\Carbon\Carbon::parse($lastBeat)) >= $interval;
            } catch (\Throwable) {
                $shouldBeat = true;
            }
        }

        if ($shouldBeat) {
            $this->heartService->decaySignals(
                $context->preset,
                $context->get('decay_hours', 24)
            );
            $this->pluginMetadata->set(
                $context->preset,
                self::PLUGIN_NAME,
                'last_beat',
                now()->toISOString()
            );
        }
    }

    /**
     * Parse "entity: value" content.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseEntityValue(string $content): array
    {
        $pos = strpos($content, ':');

        if ($pos === false) {
            $entity = trim($content);
            return $entity !== '' ? [$entity, null] : [null, null];
        }

        $entity = trim(substr($content, 0, $pos));
        $value  = trim(substr($content, $pos + 1));

        if ($entity === '') {
            return [null, null];
        }

        return [$entity, $value !== '' ? $value : null];
    }
}
