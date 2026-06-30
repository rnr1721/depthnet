<?php

namespace App\Services\Agent\Behavior;

use App\Contracts\Agent\Behavior\BehaviorPatternServiceInterface;
use App\Models\AiPreset;
use App\Models\BehaviorPattern;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * BehaviorPatternService — the cold-path bridge between the BehaviorPattern model
 * and the agent/plugin. CRUD + lifecycle; never per-cycle hot writes.
 *
 * Mirrors ContractService:
 *   - reads return models; writes validate then persist;
 *   - lifecycle enforces a legal status graph (active ⇄ hypothesis ⇄ retired);
 *   - immune interlock: an immune pattern's DEFINITION cannot be edited or
 *     deleted in place — it must first be revoked to hypothesis (a logged,
 *     reversible act), edited there, then re-promoted. "Power exists, but is not
 *     immediate." Immunity protects the definition, not the fitness.
 */
class BehaviorPatternService implements BehaviorPatternServiceInterface
{
    /** Lever delta bounds: a lever NUDGES, it does not teleport. See validate(). */
    private const LEVER_DELTA_MIN = -0.5;
    private const LEVER_DELTA_MAX = 0.5;

    /**
     * Legal status transitions. Applies to all patterns (immune included); the
     * immune interlock guards definition edits/deletes, not transitions, because
     * transitions are reversible and (for retire) non-destructive.
     *
     * @var array<string, string[]>
     */
    private const LEGAL_TRANSITIONS = [
        'hypothesis' => ['active'],                 // promote
        'active'     => ['retired', 'hypothesis'],  // retire, revoke
        'retired'    => ['active', 'hypothesis'],   // re-activate, revoke
    ];

    private const VALID_STATUSES = ['hypothesis', 'active', 'retired'];

    public function __construct(
        protected DatabaseManager $db,
        protected LoggerInterface $logger,
    ) {
    }

    // ── Read ───────────────────────────────────────────────────────────────────

    public function all(AiPreset $preset): array
    {
        return BehaviorPattern::query()
            ->forPreset($preset->getId())
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function ofStatus(AiPreset $preset, string $status): array
    {
        return BehaviorPattern::query()
            ->forPreset($preset->getId())
            ->where('status', $status)
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function find(AiPreset $preset, string $name): ?BehaviorPattern
    {
        return BehaviorPattern::query()
            ->forPreset($preset->getId())
            ->where('name', $name)
            ->first();
    }

    // ── Write ──────────────────────────────────────────────────────────────────

    public function save(AiPreset $preset, array $definition): array
    {
        $errors = $this->validate($definition);
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Pattern is invalid.', 'errors' => $errors];
        }

        $name = (string) $definition['name'];

        try {
            return $this->db->transaction(function () use ($preset, $definition, $name) {
                $existing = BehaviorPattern::query()
                    ->forPreset($preset->getId())
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();

                // ── Immune interlock ─────────────────────────────────────────
                // The definition of a live immune pattern cannot be rewritten in
                // place. Revoke it to hypothesis first (logged, reversible), edit
                // there, then re-promote. Immunity protects the definition; decay
                // still applies and the quota still keeps it activating.
                if ($existing && $existing->isDefinitionLocked()) {
                    return [
                        'success' => false,
                        'message' => "Pattern '{$name}' is immune and not in hypothesis. "
                            . 'Revoke it to hypothesis before editing.',
                        'errors'  => [],
                    ];
                }

                $attrs = $this->toColumns($preset, $definition, $existing);

                if ($existing) {
                    $existing->update($attrs);
                    $verb = 'updated';
                } else {
                    BehaviorPattern::create($attrs);
                    $verb = 'created';
                }

                $status = $attrs['status'];
                return [
                    'success' => true,
                    'message' => "Pattern '{$name}' {$verb} [{$status}].",
                    'errors'  => [],
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('BehaviorPatternService::save error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error saving pattern: ' . $e->getMessage(), 'errors' => []];
        }
    }

    public function transition(AiPreset $preset, string $name, string $to, ?string $reason = null): array
    {
        if (!in_array($to, self::VALID_STATUSES, true)) {
            return ['success' => false, 'message' => "Unknown status '{$to}'."];
        }

        try {
            return $this->db->transaction(function () use ($preset, $name, $to) {
                $row = BehaviorPattern::query()
                    ->forPreset($preset->getId())
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    return ['success' => false, 'message' => "Pattern '{$name}' not found."];
                }

                $from = $row->status;

                if ($from === $to) {
                    return ['success' => false, 'message' => "Pattern '{$name}' is already {$to}."];
                }

                $allowed = self::LEGAL_TRANSITIONS[$from] ?? [];
                if (!in_array($to, $allowed, true)) {
                    return [
                        'success' => false,
                        'message' => "Illegal transition for '{$name}': {$from} → {$to}.",
                    ];
                }

                $row->update(['status' => $to]);

                return [
                    'success' => true,
                    'message' => "Pattern '{$name}': {$from} → {$to}.",
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('BehaviorPatternService::transition error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error transitioning pattern: ' . $e->getMessage()];
        }
    }

    public function delete(AiPreset $preset, string $name): bool
    {
        try {
            return (bool) $this->db->transaction(function () use ($preset, $name) {
                $row = BehaviorPattern::query()
                    ->forPreset($preset->getId())
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    return false;
                }

                // Same interlock as edit: a live immune pattern can't be deleted
                // in place — revoke to hypothesis first.
                if ($row->isDefinitionLocked()) {
                    return false;
                }

                return (bool) $row->delete();
            });
        } catch (\Throwable $e) {
            $this->logger->error('BehaviorPatternService::delete error: ' . $e->getMessage());
            return false;
        }
    }

    public function clear(AiPreset $preset): int
    {
        try {
            // Honour the interlock: never bulk-delete a live immune pattern.
            return BehaviorPattern::query()
                ->forPreset($preset->getId())
                ->where(function ($q) {
                    $q->where('immune', false)
                      ->orWhere('status', 'hypothesis');
                })
                ->delete();
        } catch (\Throwable $e) {
            $this->logger->error('BehaviorPatternService::clear error: ' . $e->getMessage());
            return 0;
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Cross-field validation. Returns human-readable errors; empty = valid.
     *
     * @param array $d
     * @return string[]
     */
    private function validate(array $d): array
    {
        $errors = [];

        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Pattern name is required.';
        }

        // Trigger must be an object carrying a "kind".
        $trigger = $d['trigger'] ?? null;
        if (!is_array($trigger) || $trigger === []) {
            $errors[] = 'trigger is required (a JSON object with a "kind").';
        } elseif (!isset($trigger['kind']) || trim((string) $trigger['kind']) === '') {
            $errors[] = 'trigger.kind is required (e.g. "mood", "pulse").';
        }

        // Status, if supplied, must be legal.
        if (isset($d['status']) && !in_array((string) $d['status'], self::VALID_STATUSES, true)) {
            $errors[] = 'status must be one of: ' . implode(', ', self::VALID_STATUSES) . '.';
        }

        // Quota sanity: a forced interval only makes sense on an immune pattern.
        $immune = (bool) ($d['immune'] ?? false);
        $interval = $d['forced_activation_interval'] ?? null;
        if ($interval !== null) {
            if (!is_numeric($interval) || (int) $interval < 1) {
                $errors[] = 'forced_activation_interval must be a positive integer.';
            } elseif (!$immune) {
                $errors[] = 'forced_activation_interval requires immune=true '
                    . '(the quota is the reservation that protects an immune pattern from starvation).';
            }
        }

        // ── Lever (phase 2a) ─────────────────────────────────────────────────
        // Optional. If present it must be a well-formed nudge: a non-empty target
        // dimension and a signed delta within bounds. A lever that is malformed
        // would enact nothing AND generate no discriminating outcome silently —
        // so we reject it up front rather than let it pretend to be a lever.
        $lever = $d['lever'] ?? null;
        if ($lever !== null) {
            if (!is_array($lever) || $lever === []) {
                $errors[] = 'lever, if present, must be a JSON object { "dimension": "...", "delta": n }.';
            } else {
                $dimension = trim((string) ($lever['dimension'] ?? ''));
                if ($dimension === '') {
                    $errors[] = 'lever.dimension is required (the mood dimension the pattern nudges, e.g. "tenderness").';
                }

                if (!isset($lever['delta']) || !is_numeric($lever['delta'])) {
                    $errors[] = 'lever.delta is required and must be numeric.';
                } else {
                    $delta = (float) $lever['delta'];
                    if ($delta === 0.0) {
                        $errors[] = 'lever.delta must be non-zero (a zero nudge moves nothing).';
                    } elseif ($delta < self::LEVER_DELTA_MIN || $delta > self::LEVER_DELTA_MAX) {
                        $errors[] = sprintf(
                            'lever.delta must be within [%g, %g] — a lever nudges, it does not teleport. '
                            . 'A large push fills the dimension and leaves the moment no room to show surplus, '
                            . 'so the hypothesis can never confirm.',
                            self::LEVER_DELTA_MIN,
                            self::LEVER_DELTA_MAX,
                        );
                    }
                }
            }
        }

        return $errors;
    }


    /**
     * Flatten a definition array into model columns. On UPDATE we only touch the
     * authoring fields — never the hot selection state. A new pattern gets sane
     * defaults for those via the migration.
     *
     * @param BehaviorPattern|null $existing
     */
    private function toColumns(AiPreset $preset, array $d, ?BehaviorPattern $existing): array
    {
        $cols = [
            'preset_id'   => $preset->getId(),
            'name'        => (string) $d['name'],
            'trigger'     => $d['trigger'],
            'intent'      => isset($d['intent']) ? (string) $d['intent'] : null,
            'behavior'    => $d['behavior'] ?? null,
            'constraints' => $d['constraints'] ?? null,
            // Phase 2a: the enactment lever. Normalized to {dimension, delta} or
            // null. A null lever is a phase-1 cosmetic pattern (leans, enacts
            // nothing) — fully backward compatible.
            'lever'       => $this->normalizeLeverForStorage($d['lever'] ?? null),
            'provenance'  => isset($d['provenance']) ? (string) $d['provenance'] : 'agent',
            'priority'    => isset($d['priority']) ? (float) $d['priority'] : 1.0,
            'immune'      => (bool) ($d['immune'] ?? false),
            'forced_activation_interval' => isset($d['forced_activation_interval'])
                ? (int) $d['forced_activation_interval']
                : null,
            'status'      => isset($d['status']) ? (string) $d['status'] : 'hypothesis',
        ];

        if (!$existing) {
            $cols['fitness']             = 0.0;
            $cols['confidence']          = 0.0;
            $cols['plasticity']          = isset($d['plasticity']) ? (float) $d['plasticity'] : 1.0;
            $cols['activation_count']    = 0;
            $cols['last_activation_seq'] = null;
        }

        return $cols;
    }

    /**
     * Normalize a lever definition to the canonical stored shape or null. Keeps the
     * column clean: a present lever is always exactly {dimension:string, delta:float}.
     */
    private function normalizeLeverForStorage(mixed $lever): ?array
    {
        if (!is_array($lever) || $lever === []) {
            return null;
        }

        $dimension = trim((string) ($lever['dimension'] ?? ''));
        if ($dimension === '' || !isset($lever['delta']) || !is_numeric($lever['delta'])) {
            return null;
        }

        return [
            'dimension' => $dimension,
            'delta'     => (float) $lever['delta'],
        ];
    }


}
