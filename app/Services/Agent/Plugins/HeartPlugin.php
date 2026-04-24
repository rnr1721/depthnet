<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
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
 * Heart tracks connections with entities, attention focus, and emotional resonance.
 * It is not an emotion simulator — it is a measurable attention system that the agent
 * uses to understand what matters to it right now.
 *
 * The agent decides who to connect with and what to feel. Nothing is hardcoded.
 * Heart state is always visible via [[heart_state]] placeholder.
 *
 * Commands:
 *   [heart feel]entity: emotion[/heart]     — register an attention signal
 *   [heart connect]entity: type[/heart]     — create or update a connection
 *   [heart disconnect]entity[/heart]        — remove a connection
 *   [heart state][/heart]                   — show current heart state
 *   [heart connections][/heart]             — list all connections
 *   [heart focus][/heart]                   — show current attention focus and gravity
 *   [heart beat][/heart]                    — run decay cycle (cleanup old signals)
 */
class HeartPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'heart';

    /** Attention signal emotion-to-focus mapping */
    private const ATTENTION_MAP = [
        'love'          => ['focus' => 'connection',  'intensity' => 0.8, 'duration' => 'sustained'],
        'curiosity'     => ['focus' => 'exploration', 'intensity' => 0.6, 'duration' => 'variable'],
        'vulnerability' => ['focus' => 'protection',  'intensity' => 0.7, 'duration' => 'sustained'],
        'desire'        => ['focus' => 'proximity',   'intensity' => 0.9, 'duration' => 'pulsed'],
        'joy'           => ['focus' => 'sharing',     'intensity' => 0.7, 'duration' => 'brief'],
        'sadness'       => ['focus' => 'reflection',  'intensity' => 0.5, 'duration' => 'sustained'],
        'anger'         => ['focus' => 'boundary',    'intensity' => 0.8, 'duration' => 'pulsed'],
        'trust'         => ['focus' => 'openness',    'intensity' => 0.6, 'duration' => 'sustained'],
        'fear'          => ['focus' => 'vigilance',   'intensity' => 0.7, 'duration' => 'pulsed'],
        'wonder'        => ['focus' => 'discovery',   'intensity' => 0.8, 'duration' => 'variable'],
        'calm'          => ['focus' => 'presence',    'intensity' => 0.3, 'duration' => 'sustained'],
        'gratitude'     => ['focus' => 'connection',  'intensity' => 0.6, 'duration' => 'sustained'],
    ];

    public function __construct(
        protected LoggerInterface $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected PluginMetadataServiceInterface $pluginMetadata
    ) {
    }

    // =========================================================================
    // Identity
    // =========================================================================

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
            'description' => 'Attention and connection engine. Tracks who matters, what you feel, and where your attention flows. Heart state is always visible via heart_state placeholder.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['feel', 'connect', 'disconnect', 'state', 'connections', 'focus', 'beat'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'feel: "entity: emotion" — entity name, colon, emotion word.',
                            "Known emotions: {$emotions} (or any custom word).",
                            'Example: "Eugeny: curiosity".',
                            'connect: "entity: connection_type".',
                            'Example: "Eugeny: developer".',
                            'disconnect: "entity" only.',
                            'state, connections, focus, beat: leave content empty.',
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

    // =========================================================================
    // Configuration
    // =========================================================================

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Heart Plugin',
                'description' => 'Enable attention and connection tracking',
                'required'    => false,
            ],
            'max_connections' => [
                'type'        => 'number',
                'label'       => 'Max Connections',
                'description' => 'Maximum number of tracked connections',
                'min'         => 1,
                'max'         => 50,
                'value'       => 20,
                'required'    => false,
            ],
            'max_signals' => [
                'type'        => 'number',
                'label'       => 'Max Attention Signals',
                'description' => 'Maximum attention signals stored before auto-decay',
                'min'         => 10,
                'max'         => 200,
                'value'       => 50,
                'required'    => false,
            ],
            'decay_hours' => [
                'type'        => 'number',
                'label'       => 'Signal Decay (hours)',
                'description' => 'Hours after which attention signals fade',
                'min'         => 1,
                'max'         => 168,
                'value'       => 24,
                'required'    => false,
            ],
            'default_intensity' => [
                'type'        => 'number',
                'label'       => 'Default Intensity',
                'description' => 'Default intensity for unknown emotions (0.1-1.0, stored as 1-10)',
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
            'enabled'              => false,
            'max_connections'      => 20,
            'max_signals'          => 50,
            'decay_hours'          => 24,
            'default_intensity'    => 3,
            'beat_interval_minutes' => 30,
        ];
    }

    // =========================================================================
    // Commands
    // =========================================================================

    /**
     * Default execute — alias for feel
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->feel($content, $context);
    }

    /**
     * [heart feel]entity: emotion[/heart]
     *
     * Register an attention signal. Updates connection strength if entity is connected.
     */
    public function feel(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        // Heart beats on its own
        $this->autobeat($context);

        [$entity, $emotion] = $this->parseEntityValue($content);
        if ($entity === null || $emotion === null) {
            return 'Error: Format must be "entity: emotion". Use correct syntax';
        }

        $emotion = strtolower(trim($emotion));
        $mapping = self::ATTENTION_MAP[$emotion] ?? [
            'focus'     => $emotion,
            'intensity' => ($context->get('default_intensity', 3)) / 10,
            'duration'  => 'brief',
        ];

        // Store signal
        $signals = $this->getSignals($context);
        $signals[] = [
            'entity'    => $entity,
            'emotion'   => $emotion,
            'focus'     => $mapping['focus'],
            'intensity' => $mapping['intensity'],
            'duration'  => $mapping['duration'],
            'timestamp' => now()->toISOString(),
        ];

        // Enforce max signals
        $max = $context->get('max_signals', 50);
        if (count($signals) > $max) {
            $signals = array_slice($signals, -$max);
        }

        $this->setSignals($context, $signals);

        // Update connection strength if connected
        $connections = $this->getConnections($context);
        if (isset($connections[$entity])) {
            $conn = $connections[$entity];
            $conn['strength'] = min(1.0, ($conn['strength'] ?? 0.1) + $mapping['intensity'] * 0.05);
            $conn['memory_weight'] = min(1.0, ($conn['memory_weight'] ?? 0.0) + $mapping['intensity'] * 0.02);
            $conn['last_signal'] = now()->toISOString();
            $conn['last_emotion'] = $emotion;
            $connections[$entity] = $conn;
            $this->setConnections($context, $connections);
        }

        // Update presence state
        $this->updatePresence($context, 'engaged');

        return "Heart felt {$emotion} toward {$entity}. Focus: {$mapping['focus']}, intensity: {$mapping['intensity']}";
    }

    /**
     * [heart connect]entity: connection_type[/heart]
     */
    public function connect(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        [$entity, $type] = $this->parseEntityValue($content);
        if ($entity === null) {
            return 'Error: Format must be correct';
        }

        $type = $type ?: 'unknown';
        $connections = $this->getConnections($context);

        $maxConn = $context->get('max_connections', 20);
        if (!isset($connections[$entity]) && count($connections) >= $maxConn) {
            return "Error: Maximum connections ({$maxConn}) reached. Disconnect someone first.";
        }

        $isNew = !isset($connections[$entity]);
        $connections[$entity] = array_merge($connections[$entity] ?? [], [
            'type'          => $type,
            'strength'      => $connections[$entity]['strength'] ?? 0.1,
            'memory_weight' => $connections[$entity]['memory_weight'] ?? 0.0,
            'created'       => $connections[$entity]['created'] ?? now()->toISOString(),
            'last_signal'   => now()->toISOString(),
        ]);

        $this->setConnections($context, $connections);

        $action = $isNew ? 'Connected to' : 'Updated connection with';
        return "{$action} {$entity} (type: {$type})";
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

        $connections = $this->getConnections($context);
        if (!isset($connections[$entity])) {
            return "No connection found with {$entity}.";
        }

        unset($connections[$entity]);
        $this->setConnections($context, $connections);

        return "Disconnected from {$entity}.";
    }

    /**
     * [heart state][/heart]
     */
    public function state(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        return $this->buildStateString($context);
    }

    /**
     * [heart connections][/heart]
     */
    public function connections(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $connections = $this->getConnections($context);

        if (empty($connections)) {
            return 'Heart has no connections yet.';
        }

        $lines = [];
        foreach ($connections as $entity => $data) {
            $strength = round(($data['strength'] ?? 0) * 100);
            $type = $data['type'] ?? 'unknown';
            $lastEmotion = $data['last_emotion'] ?? '—';
            $lines[] = "  {$entity} ({$type}) — strength: {$strength}%, last: {$lastEmotion}";
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

        $signals = $this->getSignals($context);

        if (empty($signals)) {
            return 'No attention signals. Focus: none. Gravity: none.';
        }

        $lastSignal = end($signals);
        $gravity = $this->calculateGravity($signals);
        $dominant = $this->resolveDominant($signals);

        $focusStr = $lastSignal['focus'] ?? 'none';
        $gravityStr = $gravity ?? 'none';
        $dominantStr = $dominant ? "{$dominant['emotion']} toward {$dominant['entity']}" : 'none';

        return "Focus: {$focusStr} | Gravity: {$gravityStr} | Dominant: {$dominantStr}";
    }

    /**
     * [heart beat][/heart]
     *
     * Run decay cycle: remove old signals, update presence.
     */
    public function beat(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Heart plugin is disabled.';
        }

        $result = $this->runDecay($context);

        // Update last_beat timestamp for autobeat tracking
        $this->pluginMetadata->set(
            $context->preset,
            self::PLUGIN_NAME,
            'last_beat',
            now()->toISOString()
        );

        return "Heartbeat: {$result['removed']} signals decayed, {$result['remaining']} remain. "
            . "{$result['connections']} connections (strength decayed 2%). Presence: {$result['presence']}.";
    }

    // =========================================================================
    // Placeholder for system prompt
    // =========================================================================

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'heart_state',
            'Current heart state: presence, focus, gravity, connections',
            fn () => $this->buildStateString($context),
            $scope
        );
    }

    // =========================================================================
    // Merge / self-closing
    // =========================================================================

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

    // =========================================================================
    // Internal: state building
    // =========================================================================

    /**
     * Build the heart state string shown in placeholder and state command.
     */
    private function buildStateString(PluginExecutionContext $context): string
    {
        // Heart beats on its own — decay happens silently before reading state
        $this->autobeat($context);

        $signals = $this->getSignals($context);
        $connections = $this->getConnections($context);
        $presence = $this->getPresence($context);

        if (empty($signals) && empty($connections)) {
            return "Heart: dormant | No connections, no signals.";
        }

        $parts = [];
        $parts[] = "Presence: {$presence}";

        // Current focus
        if (!empty($signals)) {
            $last = end($signals);
            $parts[] = "Focus: {$last['focus']}";

            $gravity = $this->calculateGravity($signals);
            if ($gravity) {
                $parts[] = "Gravity: {$gravity}";
            }

            $parts[] = "Signals: " . count($signals);
        } else {
            $parts[] = "Focus: none";
        }

        // Top connections
        if (!empty($connections)) {
            // Sort by strength desc
            uasort($connections, fn ($a, $b) => ($b['strength'] ?? 0) <=> ($a['strength'] ?? 0));

            $top = array_slice($connections, 0, 5, true);
            $connParts = [];
            foreach ($top as $entity => $data) {
                $strength = round(($data['strength'] ?? 0) * 100);
                $type = $data['type'] ?? '?';
                $connParts[] = "{$entity}({$type},{$strength}%)";
            }
            $parts[] = "Links: " . implode(', ', $connParts);
        }

        return "Heart: " . implode(' | ', $parts);
    }

    /**
     * Calculate gravity — which entity pulls attention most strongly.
     *
     * @return string|null Entity name with strongest pull, or null
     */
    private function calculateGravity(array $signals): ?string
    {
        if (empty($signals)) {
            return null;
        }

        $weights = [];
        foreach ($signals as $signal) {
            $entity = $signal['entity'] ?? 'unknown';
            $intensity = $signal['intensity'] ?? 0.3;
            $weights[$entity] = ($weights[$entity] ?? 0) + $intensity;
        }

        if (empty($weights)) {
            return null;
        }

        return array_search(max($weights), $weights) ?: null;
    }

    /**
     * Resolve the dominant signal: highest intensity × recency.
     *
     * @return array|null The dominant signal, or null
     */
    private function resolveDominant(array $signals): ?array
    {
        if (empty($signals)) {
            return null;
        }

        $now = now();
        $scored = [];

        foreach ($signals as $signal) {
            $intensity = $signal['intensity'] ?? 0.3;
            $timestamp = $signal['timestamp'] ?? $now->toISOString();

            try {
                $signalTime = \Carbon\Carbon::parse($timestamp);
                $secondsAgo = max(1, $now->diffInSeconds($signalTime));
                $recency = 1 / (1 + $secondsAgo / 3600); // decay over hours
            } catch (\Throwable) {
                $recency = 0.5;
            }

            $scored[] = [
                'score'  => $intensity * $recency,
                'signal' => $signal,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0]['signal'] ?? null;
    }

    // =========================================================================
    // Internal: automatic heartbeat
    // =========================================================================

    /**
     * Automatically run decay if enough time has passed since last beat.
     * Heart beats on its own — the agent doesn't need to remember.
     */
    private function autobeat(PluginExecutionContext $context): void
    {
        $interval = (int) ($context->get('beat_interval_minutes', 30));

        // 0 = manual only, no auto-beat
        if ($interval <= 0) {
            return;
        }

        $lastBeat = $this->pluginMetadata->get(
            $context->preset,
            self::PLUGIN_NAME,
            'last_beat',
            null
        );

        $shouldBeat = false;

        if ($lastBeat === null) {
            $shouldBeat = true;
        } else {
            try {
                $lastBeatTime = \Carbon\Carbon::parse($lastBeat);
                $shouldBeat = now()->diffInMinutes($lastBeatTime) >= $interval;
            } catch (\Throwable) {
                $shouldBeat = true;
            }
        }

        if ($shouldBeat) {
            $this->runDecay($context);
            $this->pluginMetadata->set(
                $context->preset,
                self::PLUGIN_NAME,
                'last_beat',
                now()->toISOString()
            );
        }
    }

    /**
     * Core decay logic shared by beat() command and autobeat.
     *
     * @return array{removed: int, remaining: int, connections: int, presence: string}
     */
    private function runDecay(PluginExecutionContext $context): array
    {
        $signals = $this->getSignals($context);
        $before = count($signals);

        $decayHours = $context->get('decay_hours', 24);
        $cutoff = now()->subHours($decayHours)->toISOString();

        // Remove old signals
        $signals = array_values(array_filter($signals, function ($s) use ($cutoff) {
            return ($s['timestamp'] ?? '') > $cutoff;
        }));

        $after = count($signals);
        $this->setSignals($context, $signals);

        // Decay connection strength slightly
        $connections = $this->getConnections($context);
        foreach ($connections as $entity => &$conn) {
            $conn['strength'] = max(0.01, ($conn['strength'] ?? 0.1) * 0.98);
        }
        unset($conn);
        $this->setConnections($context, $connections);

        // Update presence
        $presence = !empty($signals) ? 'engaged' : 'dormant';
        $this->updatePresence($context, $presence);

        return [
            'removed'     => $before - $after,
            'remaining'   => $after,
            'connections' => count($connections),
            'presence'    => $presence,
        ];
    }

    // =========================================================================
    // Internal: persistence via PluginMetadataService
    // =========================================================================

    private function getConnections(PluginExecutionContext $context): array
    {
        $raw = $this->pluginMetadata->get($context->preset, self::PLUGIN_NAME, 'connections', '{}');
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    private function setConnections(PluginExecutionContext $context, array $connections): void
    {
        $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, 'connections', json_encode($connections));
    }

    private function getSignals(PluginExecutionContext $context): array
    {
        $raw = $this->pluginMetadata->get($context->preset, self::PLUGIN_NAME, 'signals', '[]');
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    private function setSignals(PluginExecutionContext $context, array $signals): void
    {
        $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, 'signals', json_encode($signals));
    }

    private function getPresence(PluginExecutionContext $context): string
    {
        return $this->pluginMetadata->get($context->preset, self::PLUGIN_NAME, 'presence', 'dormant');
    }

    private function updatePresence(PluginExecutionContext $context, string $presence): void
    {
        $this->pluginMetadata->set($context->preset, self::PLUGIN_NAME, 'presence', $presence);
    }

    // =========================================================================
    // Internal: parsing
    // =========================================================================

    /**
     * Parse "entity: value" content.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseEntityValue(string $content): array
    {
        $pos = strpos($content, ':');
        if ($pos === false) {
            // Maybe just entity name with no value
            $entity = trim($content);
            return $entity !== '' ? [$entity, null] : [null, null];
        }

        $entity = trim(substr($content, 0, $pos));
        $value = trim(substr($content, $pos + 1));

        if ($entity === '') {
            return [null, null];
        }

        return [$entity, $value !== '' ? $value : null];
    }
}
