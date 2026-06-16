<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Contract\ContractRuntimeServiceInterface;
use App\Contracts\Agent\Contract\ContractServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contract\{
    StoreContractRequest,
    TransitionContractRequest,
    DeleteContractRequest,
};
use App\Services\Agent\Contract\ContractDefinition;
use App\Services\Agent\Contract\ContractForm;
use App\Services\Agent\Contract\ContractStatus;
use App\Services\Agent\Contract\ContractTickRunner;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin CRUD + lifecycle for metabolism contracts.
 *
 * Reads go through ContractService (which returns immutable ContractDefinition
 * DTOs — never Eloquent), exactly as the engine sees them, so the UI and the
 * engine agree on what a contract is. Raised flags come from the runtime service,
 * so the admin sees the same live signal the agent reads via [[active_contracts]].
 *
 * Lifecycle (promote/suspend/resume/revoke) is delegated to
 * ContractService::transition() — the controller never reaches into the model.
 * The vital interlock and legal-transition table are enforced there, and their
 * refusal messages flow straight back as flash errors.
 */
class ContractController extends Controller
{
    public function __construct(
        protected ContractServiceInterface        $contractService,
        protected ContractRuntimeServiceInterface $runtime,
        protected PresetRegistryInterface         $presetRegistry,
    ) {
    }

    public function index(Request $request)
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id'         => $preset->id,
                'name'       => $preset->name,
                'is_default' => $preset->is_default,
            ])
            ->sortByDesc('is_default')
            ->values();

        $currentPresetId = $request->get('preset_id', $this->presetRegistry->getDefaultPreset()->id);
        $currentPreset   = $this->presetRegistry->getPresetOrDefault($currentPresetId);

        $filterStatus = $request->get('status', '');

        $contracts = [];
        $stats     = [];
        $flags     = [];

        if ($currentPreset) {
            $all = $this->contractService->all($currentPreset);

            // Status counts for the stat strip.
            $byStatus = ['hypothesis' => 0, 'active' => 0, 'suspended' => 0];
            foreach ($all as $c) {
                $byStatus[$c->status->value] = ($byStatus[$c->status->value] ?? 0) + 1;
            }

            $stats = [
                'total'    => count($all),
                'by_status' => $byStatus,
            ];

            // Raised flags — the live result of the metabolism, same as the agent sees.
            $flags = $this->runtime->raisedFlags($currentPreset);

            $visible = $filterStatus
                ? array_filter($all, fn ($c) => $c->status->value === $filterStatus)
                : $all;

            $contracts = array_values(array_map(
                fn (ContractDefinition $c) => $this->presentContract($c),
                $visible,
            ));
        }

        return Inertia::render('Admin/Contract/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'contracts'    => $contracts,
            'stats'        => $stats,
            'flags'        => $flags,
            'filterStatus' => $filterStatus,
            'forms'        => array_map(fn ($f) => $f->value, ContractForm::cases()),
            'statuses'     => array_map(fn ($s) => $s->value, ContractStatus::cases()),
        ]);
    }

    public function store(StoreContractRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        try {
            $definition = ContractDefinition::fromArray($request->getDefinitionArray());
        } catch (\Throwable $e) {
            return back()->with('error', 'Contract is invalid: ' . $e->getMessage());
        }

        $result = $this->contractService->save($preset, $definition);

        if (!$result['success']) {
            $detail = !empty($result['errors']) ? ' (' . implode('; ', $result['errors']) . ')' : '';
            return back()->with('error', $result['message'] . $detail);
        }

        return back()->with('success', $result['message']);
    }

    public function destroy(DeleteContractRequest $request, string $name)
    {
        $preset  = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $deleted = $this->contractService->delete($preset, $name);

        return $deleted
            ? back()->with('success', "Contract '{$name}' deleted.")
            : back()->with('error', "Could not delete '{$name}'. A vital contract must be revoked to hypothesis first.");
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function promote(TransitionContractRequest $request, string $name)
    {
        return $this->transition($request, $name, ContractStatus::ACTIVE, 'promote');
    }

    public function suspend(TransitionContractRequest $request, string $name)
    {
        return $this->transition($request, $name, ContractStatus::SUSPENDED, 'suspend');
    }

    public function resume(TransitionContractRequest $request, string $name)
    {
        return $this->transition($request, $name, ContractStatus::ACTIVE, 'resume');
    }

    public function revoke(TransitionContractRequest $request, string $name)
    {
        return $this->transition($request, $name, ContractStatus::HYPOTHESIS, 'revoke');
    }

    // ── Debug: tick now ────────────────────────────────────────────────────────

    /**
     * Manually tick the engine for the selected preset — a debugging convenience
     * so the admin doesn't have to wait for the scheduler. Respects enablement
     * and locks exactly as the scheduled run does (the runner is the same).
     */
    public function tick(TransitionContractRequest $request, ContractTickRunner $runner)
    {
        $presetId = (int) $request->validated('preset_id');
        $row      = $runner->tickPreset($presetId);

        if ($row === null) {
            return back()->with('error', 'Tick skipped: contract engine is disabled for this preset.');
        }

        return back()->with('success', "Ticked: {$row['outcome']} — {$row['detail']}");
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function transition(
        TransitionContractRequest $request,
        string $name,
        ContractStatus $to,
        string $reason,
    ) {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        // max_active_contracts cap applies only when entering active; read it from
        // the resolved plugin config the same way the agent's own promote does.
        // The controller doesn't have the plugin context here, so it passes 0
        // (no cap) — the cap is an agent-facing guard against runaway self-
        // authoring, not an admin restriction. An admin promoting by hand is a
        // deliberate act and isn't capped.
        $result = $this->contractService->transition($preset, $name, $to, $request->input('reason') ?: $reason);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    /**
     * Project a ContractDefinition DTO into the shape the Vue page consumes.
     * Pulls `match` back out of trigger for display convenience.
     */
    private function presentContract(ContractDefinition $c): array
    {
        return [
            'name'         => $c->name,
            'form'         => $c->form->value,
            'status'       => $c->status->value,
            'vital'        => $c->vital,
            'source'       => $c->source,
            'suspend_when' => $c->suspendWhen,
            'confidence'   => $c->confidence,
            'trigger'      => $c->trigger,
            'action'       => $c->action,
            'match'        => $c->match?->toArray(),
            'history'      => $c->history,
        ];
    }
}
