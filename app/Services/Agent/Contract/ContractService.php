<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\ContractServiceInterface;
use App\Models\AiPreset;
use App\Models\Contract as ContractModel;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * ContractService — the single bridge between the Contract model (storage) and
 * the ContractDefinition DTO (execution).
 *
 * Reads return DTOs; writes accept DTOs, validate, and persist to the model.
 * The engine depends on this and never sees Eloquent.
 *
 * Two responsibilities beyond plain CRUD:
 *   - Lifecycle: enforces the legal status graph (hypothesis ⇄ active ⇄ suspended).
 *   - Vital interlock: a vital contract's *definition* cannot be edited or deleted
 *     in place — it must first be revoked to hypothesis. See note on the guard.
 */
class ContractService implements ContractServiceInterface
{
    /**
     * Legal status transitions. Applies to all contracts (vital included);
     * the vital interlock guards *definition edits/deletes*, not transitions,
     * because transitions are inherently logged (history) and reversible.
     *
     * @var array<string, string[]>
     */
    private const LEGAL_TRANSITIONS = [
        'hypothesis' => ['active'],                 // promote
        'active'     => ['suspended', 'hypothesis'],// suspend, revoke
        'suspended'  => ['active', 'hypothesis'],   // resume, revoke
    ];

    public function __construct(
        protected ContractModel    $model,
        protected DatabaseManager  $db,
        protected LoggerInterface  $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function all(AiPreset $preset): array
    {
        return $this->model
            ->forPreset($preset->getId())
            ->orderBy('name')
            ->get()
            ->map(fn (ContractModel $c) => $c->toDomain())
            ->all();
    }

    public function ofStatus(AiPreset $preset, ContractStatus $status): array
    {
        return $this->model
            ->forPreset($preset->getId())
            ->ofStatus($status)
            ->orderBy('name')
            ->get()
            ->map(fn (ContractModel $c) => $c->toDomain())
            ->all();
    }

    public function find(AiPreset $preset, string $name): ?ContractDefinition
    {
        $row = $this->model
            ->forPreset($preset->getId())
            ->where('name', $name)
            ->first();

        return $row?->toDomain();
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    public function save(AiPreset $preset, ContractDefinition $definition): array
    {
        $errors = $definition->validate();
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Contract is invalid.', 'errors' => $errors];
        }

        try {
            return $this->db->transaction(function () use ($preset, $definition) {
                $existing = $this->model
                    ->forPreset($preset->getId())
                    ->where('name', $definition->name)
                    ->lockForUpdate()
                    ->first();

                // ── Vital interlock ──────────────────────────────────────────
                // The definition of a live vital contract cannot be rewritten in
                // place. To change it, the agent must first revoke it to
                // hypothesis (a logged, reversible, deliberate act), edit there,
                // then re-promote. This is "power exists, but is not immediate".
                if ($existing
                    && $existing->vital
                    && $existing->status !== ContractStatus::HYPOTHESIS) {
                    return [
                        'success' => false,
                        'message' => "Contract '{$definition->name}' is vital and active. "
                            . 'Revoke it to hypothesis before editing.',
                        'errors'  => [],
                    ];
                }

                $attrs = $this->toColumns($preset, $definition);

                if ($existing) {
                    $existing->update($attrs);
                    $verb = 'updated';
                } else {
                    $this->model->create($attrs);
                    $verb = 'created';
                }

                return [
                    'success' => true,
                    'message' => "Contract '{$definition->name}' {$verb} "
                        . "[{$definition->form->value}, {$definition->status->value}].",
                    'errors'  => [],
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('ContractService::save error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error saving contract: ' . $e->getMessage(), 'errors' => []];
        }
    }

    public function transition(
        AiPreset $preset,
        string $name,
        ContractStatus $to,
        ?string $reason = null,
        int $maxActive = 0
    ): array {
        try {
            return $this->db->transaction(function () use ($preset, $name, $to, $reason, $maxActive) {
                $row = $this->model
                    ->forPreset($preset->getId())
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    return ['success' => false, 'message' => "Contract '{$name}' not found."];
                }

                $from = $row->status;

                if ($from === $to) {
                    return ['success' => false, 'message' => "Contract '{$name}' is already {$to->value}."];
                }

                $allowed = self::LEGAL_TRANSITIONS[$from->value] ?? [];
                if (!in_array($to->value, $allowed, true)) {
                    return [
                        'success' => false,
                        'message' => "Illegal transition for '{$name}': {$from->value} → {$to->value}.",
                    ];
                }

                // ── Soft cap on simultaneously-active contracts ──────────────
                // Only relevant when entering ACTIVE. Counts current actives for
                // the preset; refuses with a clear message if the cap is reached.
                // 0 disables the cap (optional feature).
                if ($to === ContractStatus::ACTIVE && $maxActive > 0) {
                    $activeCount = $this->model
                        ->forPreset($preset->getId())
                        ->ofStatus(ContractStatus::ACTIVE)
                        ->count();

                    if ($activeCount >= $maxActive) {
                        return [
                            'success' => false,
                            'message' => "Cannot activate '{$name}': the active-contract limit "
                                . "({$maxActive}) is reached. Suspend or revoke another contract first.",
                        ];
                    }
                }


                // Apply through the DTO so the history entry is built the one
                // canonical way (withStatus), then persist the moved fields.
                $moved = $row->toDomain()->withStatus($to, $reason);

                $row->update([
                    'status'  => $moved->status->value,
                    'history' => $moved->history,
                ]);

                return [
                    'success' => true,
                    'message' => "Contract '{$name}': {$from->value} → {$to->value}.",
                ];
            });
        } catch (\Throwable $e) {
            $this->logger->error('ContractService::transition error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error transitioning contract: ' . $e->getMessage()];
        }
    }

    public function delete(AiPreset $preset, string $name): bool
    {
        try {
            return (bool) $this->db->transaction(function () use ($preset, $name) {
                $row = $this->model
                    ->forPreset($preset->getId())
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    return false;
                }

                // Same interlock as edit: a live vital contract can't be deleted
                // in place — revoke to hypothesis first.
                if ($row->vital && $row->status !== ContractStatus::HYPOTHESIS) {
                    return false;
                }

                return (bool) $row->delete();
            });
        } catch (\Throwable $e) {
            $this->logger->error('ContractService::delete error: ' . $e->getMessage());
            return false;
        }
    }

    public function clear(AiPreset $preset): int
    {
        try {
            return $this->model->forPreset($preset->getId())->delete();
        } catch (\Throwable $e) {
            $this->logger->error('ContractService::clear error: ' . $e->getMessage());
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Flatten a definition into model columns. `match` stays nested inside
     * `trigger` (where the canonical shape carries it), so toArray()'s trigger
     * already holds it — we just drop the keys the model stores as columns.
     */
    private function toColumns(AiPreset $preset, ContractDefinition $d): array
    {
        return [
            'preset_id'    => $preset->getId(),
            'name'         => $d->name,
            'form'         => $d->form->value,
            'status'       => $d->status->value,
            'vital'        => $d->vital,
            'source'       => $d->source,
            'suspend_when' => $d->suspendWhen,
            'confidence'   => $d->confidence,
            'trigger'      => $d->trigger,
            'action'       => $d->action,
            'history'      => $d->history,
        ];
    }
}
