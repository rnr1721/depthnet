<?php

namespace App\Services\Agent\Heart;

use App\Contracts\Agent\Heart\HeartServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * HeartService — attention and connection management.
 *
 * Persists all state via PluginMetadataService under the 'heart' plugin key.
 * The plugin itself becomes a thin command layer that delegates here.
 *
 * Storage keys:
 *   'connections' — JSON object keyed by entity name
 *   'signals'     — JSON array of signal records
 *   'presence'    — string: 'engaged' | 'dormant'
 *   'last_beat'   — ISO timestamp of last decay run
 */
class HeartService implements HeartServiceInterface
{
    private const PLUGIN_NAME = 'heart';

    public function __construct(
        protected PluginMetadataServiceInterface $pluginMetadata,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Signals ───────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function registerSignal(
        AiPreset $preset,
        string $entity,
        string $signalType,
        float $intensity,
        string $focus,
        float $valence = 0.5,
        string $duration = 'brief',
        int $maxSignals = 50
    ): void {
        $signals   = $this->getSignals($preset);
        $signals[] = [
            'entity'      => $entity,
            'signal_type' => $signalType,
            'intensity'   => $intensity,
            'focus'       => $focus,
            'valence'     => $valence,
            'duration'    => $duration,
            'timestamp'   => now()->toISOString(),
        ];

        if (count($signals) > $maxSignals) {
            $signals = array_slice($signals, -$maxSignals);
        }

        $this->persistSignals($preset, $signals);

        // Update connection strength if entity is connected
        $connections = $this->getConnections($preset);
        if (isset($connections[$entity])) {
            $conn = $connections[$entity];
            $delta = $intensity * 0.05 * $valence;
            $conn['strength']      = max(0.01, min(1.0, ($conn['strength'] ?? 0.1) + $delta));
            $conn['memory_weight'] = min(1.0, ($conn['memory_weight'] ?? 0.0) + $intensity * 0.02);
            $conn['last_signal']   = now()->toISOString();
            $conn['last_signal_type'] = $signalType;
            $connections[$entity]  = $conn;
            $this->persistConnections($preset, $connections);
        }

        $this->setPresence($preset, 'engaged');
    }

    /**
     * @inheritDoc
     */
    public function getSignals(AiPreset $preset): array
    {
        $raw = $this->pluginMetadata->get($preset, self::PLUGIN_NAME, 'signals', '[]');
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    /**
     * @inheritDoc
     */
    public function decaySignals(
        AiPreset $preset,
        int $decayHours = 24,
        float $strengthDecay = 0.98
    ): array {
        $signals = $this->getSignals($preset);
        $before  = count($signals);
        $cutoff  = now()->subHours($decayHours)->toISOString();

        $signals = array_values(array_filter(
            $signals,
            fn ($s) => ($s['timestamp'] ?? '') > $cutoff
        ));

        $this->persistSignals($preset, $signals);

        $connections = $this->getConnections($preset);
        foreach ($connections as &$conn) {
            $conn['strength'] = max(0.01, ($conn['strength'] ?? 0.1) * $strengthDecay);
        }
        unset($conn);
        $this->persistConnections($preset, $connections);

        $presence = !empty($signals) ? 'engaged' : 'dormant';
        $this->setPresence($preset, $presence);

        return [
            'removed'   => $before - count($signals),
            'remaining' => count($signals),
        ];
    }

    // ── Connections ───────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function upsertConnection(
        AiPreset $preset,
        string $entity,
        string $connectionType,
        ?float $initialStrength = null
    ): void {
        $connections = $this->getConnections($preset);

        $existing = $connections[$entity] ?? [];

        $connections[$entity] = array_merge($existing, [
            'type'             => $connectionType,
            'strength'         => $existing['strength'] ?? $initialStrength ?? 0.1,
            'memory_weight'    => $existing['memory_weight'] ?? 0.0,
            'created'          => $existing['created'] ?? now()->toISOString(),
            'last_signal'      => $existing['last_signal'] ?? null,
            'last_signal_type' => $existing['last_signal_type'] ?? null,
        ]);

        $this->persistConnections($preset, $connections);
    }

    /**
     * @inheritDoc
     */
    public function removeConnection(AiPreset $preset, string $entity): bool
    {
        $connections = $this->getConnections($preset);

        if (!isset($connections[$entity])) {
            return false;
        }

        unset($connections[$entity]);
        $this->persistConnections($preset, $connections);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getConnections(AiPreset $preset): array
    {
        $raw = $this->pluginMetadata->get($preset, self::PLUGIN_NAME, 'connections', '{}');
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    /**
     * @inheritDoc
     */
    public function getConnection(AiPreset $preset, string $entity): ?array
    {
        return $this->getConnections($preset)[$entity] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function hasConnection(AiPreset $preset, string $entity): bool
    {
        return isset($this->getConnections($preset)[$entity]);
    }

    // ── Self metrics ──────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function getSelfMetrics(AiPreset $preset): array
    {
        $signals = $this->getSignals($preset);
        $selfSignals = array_filter($signals, fn ($s) => ($s['entity'] ?? '') === 'self');

        if (empty($selfSignals)) {
            return [
                'attention' => 0.0,
                'valence'   => 0.0,
                'dominant'  => null,
                'focus'     => null,
                'cohesion'  => 1.0,
                'count'     => 0,
                'differentiation' => 0.5,
                'boundary_type'   => 'neutral',
            ];
        }

        $attention = 0.0;
        $valence   = 0.0;

        foreach ($selfSignals as $s) {
            $intensity = $s['intensity'] ?? 0.0;
            $attention += $intensity;
            $valence   += $intensity * ($s['valence'] ?? 0.0);
        }

        // Find dominant self-signal by intensity × recency (reuse getDominant logic)
        $dominant = $this->getDominantForEntity($preset, 'self');

        // Calculate cohesion from focus consistency
        $focusCounts = [];
        foreach ($selfSignals as $signal) {
            $focus = $signal['focus'] ?? 'unknown';
            $focusCounts[$focus] = ($focusCounts[$focus] ?? 0) + 1;
        }

        $dominantFocusCount = !empty($focusCounts) ? max($focusCounts) : 0;

        $cohesion = count($selfSignals) > 0
            ? round($dominantFocusCount / count($selfSignals), 2)
            : 1.0; // no signals → perfect cohesion (no conflict)

        // Calculate boundary differentiation: self-attention vs external-attention balance
        $connections = $this->getConnections($preset);
        $externalAttention = 0.0;
        foreach ($connections as $conn) {
            $externalAttention += ($conn['strength'] ?? 0.0);
        }

        $totalAttention = $attention + $externalAttention;

        if ($totalAttention < 0.1) {
            // Not enough data — neutral boundary
            $differentiation = 0.5;
            $boundaryType = 'neutral';
        } else {
            $differentiation = round($attention / $totalAttention, 2);

            if ($differentiation < 0.3) {
                $boundaryType = 'permeable';
            } elseif ($differentiation > 0.7) {
                $boundaryType = 'rigid';
            } else {
                $boundaryType = 'clear';
            }
        }

        return [
            'attention' => min(1.0, $attention),          // capped at 1.0
            'valence'   => max(-1.0, min(1.0, $valence)), // capped at ±1.0
            'dominant'  => $dominant['signal_type'] ?? null,
            'focus'     => $dominant['focus'] ?? null,
            'cohesion'  => $cohesion,
            'count'     => count($selfSignals),
            'differentiation' => $differentiation,
            'boundary_type'   => $boundaryType,
        ];
    }


    // ── Attention state ───────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function getGravity(AiPreset $preset): ?string
    {
        $signals = $this->getSignals($preset);

        if (empty($signals)) {
            return null;
        }

        $weights = [];
        foreach ($signals as $signal) {
            $entity = $signal['entity'] ?? 'unknown';

            // Self is the observer, not a gravity target
            if ($entity === 'self') {
                continue;
            }

            $intensity = $signal['intensity'] ?? 0.3;
            $weights[$entity] = ($weights[$entity] ?? 0) + $intensity;
        }

        if (empty($weights)) {
            return null; // only self-signals exist — no external gravity
        }

        return array_search(max($weights), $weights) ?: null;
    }

    /**
     * @inheritDoc
     */
    public function getPresence(AiPreset $preset): string
    {
        return $this->pluginMetadata->get($preset, self::PLUGIN_NAME, 'presence', 'dormant');
    }

    /**
     * @inheritDoc
     */
    public function getFocus(AiPreset $preset): ?string
    {
        $signals = $this->getSignals($preset);

        if (empty($signals)) {
            return null;
        }

        return end($signals)['focus'] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function buildStateString(AiPreset $preset): string
    {
        $signals     = $this->getSignals($preset);
        $connections = $this->getConnections($preset);
        $presence    = $this->getPresence($preset);
        $self        = $this->getSelfMetrics($preset);

        if (empty($signals) && empty($connections)) {
            $selfLine = $self['count'] > 0
                ? "Self Attention: " . round($self['attention'] * 100) . "%"
                : "Self Attention: 0%";
            return "Heart: dormant | {$selfLine} | No connections, no signals.";
        }

        $parts   = [];
        $parts[] = "Presence: {$presence}";

        // Self metrics block
        $selfParts = [];
        $selfParts[] = "Attention: " . round($self['attention'] * 100) . "%";
        if ($self['valence'] != 0.0) {
            $sign = $self['valence'] > 0 ? '+' : '';
            $selfParts[] = "Valence: {$sign}" . round($self['valence'] * 100) . "%";
        } else {
            $selfParts[] = "Valence: neutral";
        }
        if ($self['dominant']) {
            $selfParts[] = "Dominant: {$self['dominant']}";
        }
        if ($self['count'] > 0 && isset($self['cohesion'])) {
            $cohesionPercent = round($self['cohesion'] * 100);
            $selfParts[] = "Cohesion: {$cohesionPercent}%";
        }
        if (isset($self['boundary_type']) && $self['boundary_type'] !== 'neutral') {
            $selfParts[] = "Boundary: {$self['boundary_type']}";
        }
        $parts[] = "Self: " . implode(', ', $selfParts);

        if (!empty($signals)) {
            $last       = end($signals);
            $lastEntity = $last['entity'] ?? 'unknown';
            $focus      = $lastEntity === 'self' ? 'self' : ($last['focus'] ?? 'none');
            $gravity    = $this->getGravity($preset);
            $valence    = $last['valence'] ?? 0;
            $valenceStr = $valence > 0.3 ? 'positive' : ($valence < -0.3 ? 'negative' : 'neutral');
            $dominant   = $this->getDominant($preset);

            $parts[] = "Focus: {$focus}";
            if ($gravity) {
                $parts[] = "Gravity: {$gravity}";
            }

            if ($dominant) {
                $dominantType   = $dominant['signal_type'] ?? $dominant['emotion'] ?? 'unknown';
                $dominantTarget = ($dominant['entity'] ?? '') === 'self' ? 'self' : $dominant['entity'];
                $parts[] = "Dominant: {$dominantType} toward {$dominantTarget}";
            }

            $parts[] = "Signals: " . count($signals);

            $signalType   = $last['signal_type'] ?? $last['emotion'] ?? 'unknown';
            $signalTarget = $lastEntity === 'self' ? 'self' : 'someone';
            $parts[] = "Last signal: {$signalType} ({$valenceStr}) toward {$signalTarget}";
        } else {
            $parts[] = "Focus: none";
        }

        if (!empty($connections)) {
            uasort($connections, fn ($a, $b) => ($b['strength'] ?? 0) <=> ($a['strength'] ?? 0));
            $top       = array_slice($connections, 0, 5, true);
            $connParts = [];

            foreach ($top as $entity => $data) {
                $strength    = round(($data['strength'] ?? 0) * 100);
                $type        = $data['type'] ?? '?';
                $connParts[] = "{$entity}({$type},{$strength}%)";
            }

            $parts[] = "Links: " . implode(', ', $connParts);
        }

        return "Heart: " . implode(' | ', $parts);
    }

    // ── Presence ──────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function setPresence(AiPreset $preset, string $presence): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'presence', $presence);
    }

    /**
     * Get the dominant signal for a specific entity.
     * Uses intensity × recency scoring.
     *
     * @param  AiPreset $preset
     * @param  string   $entity
     * @return array|null Signal record, or null
     */
    private function getDominantForEntity(AiPreset $preset, string $entity): ?array
    {
        $signals = $this->getSignals($preset);

        if (empty($signals)) {
            return null;
        }

        $now = now();
        $scored = [];

        foreach ($signals as $signal) {
            if (($signal['entity'] ?? '') !== $entity) {
                continue;
            }

            $intensity = $signal['intensity'] ?? 0.3;
            try {
                $signalTime = \Carbon\Carbon::parse($signal['timestamp']);
                $secondsAgo = max(1, $now->diffInSeconds($signalTime));
                $recency = 1 / (1 + $secondsAgo / 3600);
            } catch (\Throwable) {
                $recency = 0.5;
            }

            $scored[] = [
                'score'  => $intensity * $recency,
                'signal' => $signal,
            ];
        }

        if (empty($scored)) {
            return null;
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $scored[0]['signal'] ?? null;
    }

    public function getDominant(AiPreset $preset): ?array
    {
        $signals = $this->getSignals($preset);
        if (empty($signals)) {
            return null;
        }

        $now = now();
        $scored = [];
        foreach ($signals as $signal) {
            $intensity = $signal['intensity'] ?? 0.3;
            try {
                $signalTime = \Carbon\Carbon::parse($signal['timestamp']);
                $secondsAgo = max(1, $now->diffInSeconds($signalTime));
                $recency = 1 / (1 + $secondsAgo / 3600);
            } catch (\Throwable) {
                $recency = 0.5;
            }
            $scored[] = [
                'score' => $intensity * $recency,
                'signal' => $signal
            ];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $scored[0]['signal'] ?? null;
    }

    // ── Private persistence ───────────────────────────────────────────────────

    private function persistSignals(AiPreset $preset, array $signals): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'signals', json_encode($signals));
    }

    private function persistConnections(AiPreset $preset, array $connections): void
    {
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'connections', json_encode($connections));
    }

    // ── Service ───────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset): void
    {
        $this->persistSignals($preset, []);
        $this->persistConnections($preset, []);
        $this->setPresence($preset, 'dormant');
        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, 'last_beat', null);
    }

}
