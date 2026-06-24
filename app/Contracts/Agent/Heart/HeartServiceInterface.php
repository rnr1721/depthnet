<?php

namespace App\Contracts\Agent\Heart;

use App\Models\AiPreset;

/**
 * HeartServiceInterface — attention and connection management.
 *
 * Heart is not an emotion simulator. It is a measurable attention system:
 *
 *   - Connections: persistent entities the agent tracks (people, goals, concepts)
 *   - Signals:     transient attention events with intensity, focus, and valence
 *   - Self:        aggregated metrics derived from self-directed signals
 *                  (self is the observer, not a connection)
 *   - Gravity:     which entity currently pulls attention most strongly
 *   - Presence:    overall engagement state derived from signal activity
 *
 * Valence is a signed value [-1.0 .. 1.0]:
 *   positive (+) → signal strengthens the connection
 *   neutral  (0) → signal noted but connection strength unchanged
 *   negative (-) → signal weakens the connection (tension, boundary, distance)
 *
 * For self-directed signals (entity === 'self'):
 *   - Signals preserve their original valence — negative self-signals are
 *     valid forms of self-awareness, not errors to correct
 *   - Self signals contribute to Self metrics (attention, valence, cohesion)
 *     without affecting the connection graph
 *   - Self is never stored as a connection — it is the observer, not a node
 *
 * This interface is intentionally free of emotion vocabulary.
 * "Signal" and "connection" are the primitives — what they mean
 * is defined by the agent's system prompt, not by this layer.
 */
interface HeartServiceInterface
{
    // ── Signals ───────────────────────────────────────────────────────────────

    /**
     * Register an attention signal toward an entity.
     *
     * If the entity has a connection, its strength is adjusted by
     * signal intensity × valence. Valence > 0 strengthens, < 0 weakens.
     *
     * Self-directed signals (entity === 'self') are stored with focus='self'
     * and contribute to self-metrics without affecting connections.
     *
     * @param  AiPreset $preset
     * @param  string   $entity      Entity name (person, goal, concept, or 'self')
     * @param  string   $signalType  Signal type / emotion word
     * @param  float    $intensity   0.1 – 1.0
     * @param  string   $focus       Where attention is directed (e.g. 'connection', 'boundary', 'self')
     * @param  float    $valence     -1.0 .. 1.0 (effect on connection strength; transformed for self)
     * @param  string   $duration    'brief' | 'pulsed' | 'sustained' | 'variable'
     * @param  int      $maxSignals  Maximum signals stored before oldest are dropped
     * @return void
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
    ): void;

    /**
     * Get all current attention signals, ordered oldest → newest.
     *
     * @param  AiPreset $preset
     * @return array<int, array{
     *     entity: string,
     *     signal_type: string,
     *     intensity: float,
     *     focus: string,
     *     valence: float,
     *     duration: string,
     *     timestamp: string
     * }>
     */
    public function getSignals(AiPreset $preset): array;

    /**
     * Remove signals older than the configured decay threshold.
     * Also applies a small strength decay to all connections.
     *
     * Self-directed signals decay at the same rate as external signals.
     *
     * @param  AiPreset $preset
     * @param  int      $decayHours   Hours after which signals are removed
     * @param  float    $strengthDecay Multiplier applied to connection strengths (0.0–1.0)
     * @return array{removed: int, remaining: int}
     */
    public function decaySignals(
        AiPreset $preset,
        int $decayHours = 24,
        float $strengthDecay = 0.98
    ): array;

    // ── Connections ───────────────────────────────────────────────────────────

    /**
     * Create or update a connection.
     *
     * Connection types are free-form strings: 'person', 'goal', 'concept',
     * 'place', etc. The type is semantic metadata — Heart does not act on it.
     *
     * Note: 'self' cannot be created as a connection. Self is the observer,
     * not a node in the connection graph.
     *
     * @param  AiPreset   $preset
     * @param  string     $entity          Entity name
     * @param  string     $connectionType  Free-form type label
     * @param  float|null $initialStrength 0.0 – 1.0, defaults to 0.1 for new connections
     * @return void
     */
    public function upsertConnection(
        AiPreset $preset,
        string $entity,
        string $connectionType,
        ?float $initialStrength = null
    ): void;

    /**
     * Remove a connection entirely.
     *
     * @param  AiPreset $preset
     * @param  string   $entity
     * @return bool True if connection existed and was removed
     */
    public function removeConnection(AiPreset $preset, string $entity): bool;

    /**
     * Get all connections, keyed by entity name.
     *
     * Self is never included in connections.
     *
     * @param  AiPreset $preset
     * @return array<string, array{
     *     type: string,
     *     strength: float,
     *     memory_weight: float,
     *     created: string,
     *     last_signal: string|null,
     *     last_signal_type: string|null
     * }>
     */
    public function getConnections(AiPreset $preset): array;

    /**
     * Get a single connection or null if not found.
     *
     * @param  AiPreset $preset
     * @param  string   $entity
     * @return array|null
     */
    public function getConnection(AiPreset $preset, string $entity): ?array;

    /**
     * Check whether a connection exists for the given entity.
     *
     * @param  AiPreset $preset
     * @param  string   $entity
     * @return bool
     */
    public function hasConnection(AiPreset $preset, string $entity): bool;

    // ── Self metrics ──────────────────────────────────────────────────────────

    /**
     * Get aggregated metrics derived from self-directed signals.
     *
     * Self Attention measures how much attention the agent directs inward
     * (sum of intensities of all self signals). High attention means the
     * agent is actively observing its own state.
     *
     * Self Valence measures the emotional tone of self-observation
     * (sum of intensity × valence). Positive = self-affirming signals,
     * negative = self-critical or distressed signals. Both are valid
     * forms of self-awareness.
     *
     * Cohesion measures the consistency of self-observation focus.
     * High cohesion = self-signals share a dominant focus (e.g. all "self").
     * Low cohesion = attention is scattered across different foci
     * (exploration, achievement, depletion, etc.) — internal fragmentation.
     *
     * These two metrics are independent:
     *   - High attention + negative valence = intense self-criticism
     *   - Low attention + positive valence = quiet self-contentment
     *   - High attention + positive valence = active self-appreciation
     *   - Low attention + negative valence = background unease
     *
     * @param  AiPreset $preset
     * @return array{
     *     attention: float,      // 0.0–1.0, sum of self-signal intensities (capped)
     *     valence: float,        // -1.0..1.0, sum of intensity × valence (capped)
     *     dominant: string|null, // most intense recent self-signal type
     *     focus: string|null,    // focus of the dominant self-signal
     *     cohesion: float,
     *     count: int,            // number of active self-directed signals
     *     differentiation: float,
     *     boundary_type: string  // ('permeable' | 'clear' | 'rigid' | 'neutral')
     * }
     */
    public function getSelfMetrics(AiPreset $preset): array;

    // ── Attention state ───────────────────────────────────────────────────────

    /**
     * Calculate gravity — the entity currently pulling attention most strongly.
     *
     * Gravity is derived from accumulated signal intensity across all signals,
     * weighted toward recent signals. Returns null when no signals exist.
     *
     * Self is excluded from gravity calculation — gravity measures external pull.
     *
     * @param  AiPreset $preset
     * @return string|null Entity name, or null
     */
    public function getGravity(AiPreset $preset): ?string;

    /**
     * Get the current presence state: 'engaged' | 'dormant'.
     *
     * Presence is 'engaged' when active signals exist, 'dormant' otherwise.
     *
     * @param  AiPreset $preset
     * @return string
     */
    public function getPresence(AiPreset $preset): string;

    /**
     * Get the current attention focus derived from the most recent signal.
     *
     * @param  AiPreset $preset
     * @return string|null Focus label, or null when no signals
     */
    public function getFocus(AiPreset $preset): ?string;

    /**
     * Get the dominant signal — highest intensity × recency.
     * Returns null when no signals exist.
     *
     * @param  AiPreset $preset
     * @return array|null Signal record
     */
    public function getDominant(AiPreset $preset): ?array;

    /**
     * Build a compact state string for injection into [[heart_state]].
     *
     * Format includes self metrics as a separate block, distinct from
     * connections. Self is presented as the observer, not a node.
     *
     * Format is intentionally terse — it lives in the system prompt
     * every cycle and must not consume excessive tokens.
     *
     * @param  AiPreset $preset
     * @return string
     */
    public function buildStateString(AiPreset $preset): string;

    // ── Presence ──────────────────────────────────────────────────────────────

    /**
     * Explicitly set the presence state.
     *
     * @param  AiPreset $preset
     * @param  string   $presence 'engaged' | 'dormant'
     * @return void
     */
    public function setPresence(AiPreset $preset, string $presence): void;

    // ── Service ──────────────────────────────────────────────────────────────

    /**
     * Clear all heart state for a preset:
     * signals, connections, presence, last_beat.
     *
     * Self metrics will naturally return zero values after clear
     * since they're derived from signals.
     *
     * @param  AiPreset $preset
     * @return void
     */
    public function clear(AiPreset $preset): void;
}
