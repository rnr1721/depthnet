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
            $entity   = $signal['entity'] ?? 'unknown';
            $intensity = $signal['intensity'] ?? 0.3;
            $weights[$entity] = ($weights[$entity] ?? 0) + $intensity;
        }

        return empty($weights) ? null : (array_search(max($weights), $weights) ?: null);
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

        if (empty($signals) && empty($connections)) {
            return "Heart: dormant | No connections, no signals.";
        }

        $parts   = [];
        $parts[] = "Presence: {$presence}";

        if (!empty($signals)) {
            $last    = end($signals);
            $focus   = $last['focus'] ?? 'none';
            $gravity = $this->getGravity($preset);
            $valence = $last['valence'] ?? 0;
            $valenceStr = $valence > 0.3 ? 'positive' : ($valence < -0.3 ? 'negative' : 'neutral');
            $dominant = $this->getDominant($preset);

            $parts[] = "Focus: {$focus}";
            if ($gravity) {
                $parts[] = "Gravity: {$gravity}";
            }

            if ($dominant) {
                $dominantType = $dominant['signal_type'] ?? $dominant['emotion'] ?? 'unknown';
                $parts[] = "Dominant: {$dominantType} toward {$dominant['entity']}";
            }

            $parts[] = "Signals: " . count($signals);

            $signalType = $last['signal_type'] ?? $last['emotion'] ?? 'unknown';
            $parts[] = "Last signal: {$signalType} ({$valenceStr})";
        } else {
            $parts[] = "Focus: none";
        }

        if (!empty($connections)) {
            uasort($connections, fn ($a, $b) => ($b['strength'] ?? 0) <=> ($a['strength'] ?? 0));
            $top      = array_slice($connections, 0, 5, true);
            $connParts = [];

            foreach ($top as $entity => $data) {
                $strength = round(($data['strength'] ?? 0) * 100);
                $type     = $data['type'] ?? '?';
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
