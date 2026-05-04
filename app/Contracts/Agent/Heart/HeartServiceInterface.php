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
 *   - Gravity:     which entity currently pulls attention most strongly
 *   - Presence:    overall engagement state derived from signal activity
 *
 * Valence is a signed value [-1.0 .. 1.0]:
 *   positive (+) → signal strengthens the connection
 *   neutral  (0) → signal noted but connection strength unchanged
 *   negative (-) → signal weakens the connection (tension, boundary, distance)
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
     * @param  AiPreset   $preset
     * @param  string     $entity     Entity name (person, goal, concept)
     * @param  string     $signalType Signal type / emotion word
     * @param  float      $intensity  0.1 – 1.0
     * @param  string     $focus      Where attention is directed (e.g. 'connection', 'boundary')
     * @param  float      $valence    -1.0 .. 1.0 (effect on connection strength)
     * @param  string     $duration   'brief' | 'pulsed' | 'sustained' | 'variable'
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
     * @return array<int, array{entity: string, signal_type: string, intensity: float, focus: string, valence: float, duration: string, timestamp: string}>
     */
    public function getSignals(AiPreset $preset): array;

    /**
     * Remove signals older than the configured decay threshold.
     * Also applies a small strength decay to all connections.
     *
     * @param  AiPreset $preset
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
     * @param  AiPreset   $preset
     * @param  string     $entity         Entity name
     * @param  string     $connectionType Free-form type label
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
     * @param  AiPreset $preset
     * @return array<string, array{type: string, strength: float, memory_weight: float, created: string, last_signal: string|null, last_signal_type: string|null}>
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

    // ── Attention state ───────────────────────────────────────────────────────

    /**
     * Calculate gravity — the entity currently pulling attention most strongly.
     *
     * Gravity is derived from accumulated signal intensity across all signals,
     * weighted toward recent signals. Returns null when no signals exist.
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
     * @param AiPreset $preset
     * @return array|null
     */
    public function getDominant(AiPreset $preset): ?array;

    /**
     * Build a compact state string for injection into [[heart_state]].
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
     * @param AiPreset $preset
     * @return void
     */
    public function clear(AiPreset $preset): void;

}
