<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\ContractRuntimeServiceInterface;
use App\Models\AiPreset;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * ContractRuntimeService — engine hot-path state on the contract rows.
 *
 * Uses the query builder directly against agent_contracts (not the Eloquent
 * model or ContractService) so it can write per-tick columns without disturbing
 * the CRUD/lifecycle boundary, and so flag derivation is a single indexed query.
 *
 * Flags are derived, never stored: a flag is raised iff some active, triggered
 * contract has a flag action (set_flag / create_goal) naming it. The contract
 * table is the only source of truth.
 */
class ContractRuntimeService implements ContractRuntimeServiceInterface
{
    private const TABLE = 'agent_contracts';

    /** Action types that surface as flags. */
    private const FLAG_ACTIONS = ['set_flag', 'create_goal'];

    public function __construct(
        protected DatabaseManager $db,
        protected LoggerInterface $logger,
    ) {
    }

    public function previousTriggered(AiPreset $preset, string $name): bool
    {
        $value = $this->table()
            ->where('preset_id', $preset->getId())
            ->where('name', $name)
            ->value('triggered');

        return (bool) $value;
    }

    public function recordEvaluation(
        AiPreset $preset,
        string $name,
        bool $triggered,
        bool $risingEdge,
        Carbon $now
    ): void {
        $update = [
            'triggered'         => $triggered,
            'last_evaluated_at' => $now,
            'updated_at'        => $now,
        ];

        if ($risingEdge) {
            $update['triggered_at'] = $now;
        } elseif (!$triggered) {
            // Falling edge / not met: clear the rising timestamp.
            $update['triggered_at'] = null;
        }

        $this->table()
            ->where('preset_id', $preset->getId())
            ->where('name', $name)
            ->update($update);
    }

    public function raisedFlags(AiPreset $preset): array
    {
        $rows = $this->table()
            ->where('preset_id', $preset->getId())
            ->where('status', 'active')
            ->where('triggered', true)
            ->get(['name', 'action', 'triggered_at']);

        $flags = [];

        foreach ($rows as $row) {
            $action = $this->decodeAction($row->action);
            $type   = $action['type'] ?? '';

            if (!in_array($type, self::FLAG_ACTIONS, true)) {
                continue;
            }

            $flag = (string) ($action['flag'] ?? '');
            if ($flag === '') {
                continue;
            }

            $flags[] = [
                'flag'  => $flag,
                'by'    => (string) $row->name,
                'kind'  => $type === 'create_goal' ? 'goal_candidate' : 'flag',
                'since' => $row->triggered_at !== null ? (string) $row->triggered_at : null,
            ];
        }

        return $flags;
    }

    public function isFlagRaised(AiPreset $preset, string $flag): bool
    {
        foreach ($this->raisedFlags($preset) as $raised) {
            if ($raised['flag'] === $flag) {
                return true;
            }
        }

        return false;
    }

    public function lastTickAt(AiPreset $preset): ?Carbon
    {
        $value = $this->table()
            ->where('preset_id', $preset->getId())
            ->max('last_evaluated_at');

        return $value ? Carbon::parse($value) : null;
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function table()
    {
        return $this->db->table(self::TABLE);
    }

    /**
     * The `action` column is JSON. The query builder returns it as a raw string
     * (no Eloquent cast on this path), so decode defensively.
     */
    private function decodeAction(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
