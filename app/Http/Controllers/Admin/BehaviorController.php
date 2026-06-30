<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Behavior\BehaviorDecayRunnerInterface;
use App\Contracts\Agent\Behavior\BehaviorPatternServiceInterface;
use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Behavior\{
    StoreBehaviorRequest,
    TransitionBehaviorRequest,
    DeleteBehaviorRequest,
};
use App\Models\BehaviorPattern;
use App\Services\Agent\Plugins\MoodPlugin;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin CRUD + lifecycle for adaptive behavior patterns.
 *
 * Reads go through BehaviorPatternService (the cold path, returning models),
 * exactly as authoring sees them. Selection state (fitness, activation counts,
 * cycle_seq) comes from the runtime service so the admin sees the same live
 * numbers the engine writes.
 *
 * Lifecycle (promote/retire/revoke) is delegated to
 * BehaviorPatternService::transition() — the controller never reaches into the
 * model. The immune interlock and legal-transition table are enforced there, and
 * their refusal messages flow back as flash errors.
 */
class BehaviorController extends Controller
{
    public function __construct(
        protected BehaviorPatternServiceInterface $patternService,
        protected BehaviorRuntimeServiceInterface $runtime,
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

        $patterns = [];
        $stats    = [];
        $cycleSeq = 0;

        if ($currentPreset) {
            $all = $this->patternService->all($currentPreset);

            $byStatus = ['hypothesis' => 0, 'active' => 0, 'retired' => 0];
            foreach ($all as $p) {
                $byStatus[$p->status] = ($byStatus[$p->status] ?? 0) + 1;
            }

            $stats = [
                'total'     => count($all),
                'by_status' => $byStatus,
            ];

            // The selection clock — lets the admin read "last activated N cycles ago".
            $cycleSeq = $this->runtime->currentCycleSeq($currentPreset);

            $visible = $filterStatus
                ? array_filter($all, fn (BehaviorPattern $p) => $p->status === $filterStatus)
                : $all;

            $patterns = array_values(array_map(
                fn (BehaviorPattern $p) => $this->presentPattern($p, $cycleSeq),
                $visible,
            ));
        }

        return Inertia::render('Admin/Behavior/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'patterns'     => $patterns,
            'stats'        => $stats,
            'cycleSeq'     => $cycleSeq,
            'filterStatus' => $filterStatus,
            'kinds'        => ['mood', 'pulse'],
            'statuses'     => ['hypothesis', 'active', 'retired'],
            // Phase 2a: known mood dimensions for the lever select. Sourced from
            // MoodPlugin so the list tracks the mood vocabulary automatically. The
            // agent can still author a lever on a dimension not in this list via
            // raw JSON / [behavior define]; this select just covers the common case
            // and prevents the typo that would silently desync lever from
            // discriminator (tendernes vs tenderness).
            'dimensions'   => MoodPlugin::knownDimensions(),
        ]);

    }

    public function store(StoreBehaviorRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        $result = $this->patternService->save($preset, $request->getDefinitionArray());

        if (!$result['success']) {
            $detail = !empty($result['errors']) ? ' (' . implode('; ', $result['errors']) . ')' : '';
            return back()->with('error', $result['message'] . $detail);
        }

        return back()->with('success', $result['message']);
    }

    public function destroy(DeleteBehaviorRequest $request, string $name)
    {
        $preset   = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $deleted  = $this->patternService->delete($preset, $name);

        return $deleted
            ? back()->with('success', "Pattern '{$name}' deleted.")
            : back()->with('error', "Could not delete '{$name}'. An immune pattern must be revoked to hypothesis first.");
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function promote(TransitionBehaviorRequest $request, string $name)
    {
        return $this->transition($request, $name, 'active', 'promote');
    }

    public function retire(TransitionBehaviorRequest $request, string $name)
    {
        return $this->transition($request, $name, 'retired', 'retire');
    }

    public function revoke(TransitionBehaviorRequest $request, string $name)
    {
        return $this->transition($request, $name, 'hypothesis', 'revoke');
    }

    // ── Debug: decay now ────────────────────────────────────────────────────────

    /**
     * Manually run inactivity decay for the selected preset — a debugging
     * convenience so the admin doesn't wait for the scheduler. Respects
     * enablement and locks exactly as the scheduled run does (same runner).
     */
    public function decay(TransitionBehaviorRequest $request, BehaviorDecayRunnerInterface $runner)
    {
        $presetId = (int) $request->validated('preset_id');
        $row      = $runner->decayPreset($presetId);

        if ($row === null) {
            return back()->with('error', 'Decay skipped: ABS is disabled for this preset.');
        }

        return back()->with('success', "Decay: {$row['outcome']} — {$row['detail']}");
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function transition(
        TransitionBehaviorRequest $request,
        string $name,
        string $to,
        string $reason,
    ) {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));

        $result = $this->patternService->transition($preset, $name, $to, $request->input('reason') ?: $reason);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    /**
     * Project a BehaviorPattern into the shape the Vue page consumes. cycleSeq is
     * passed so the page can show "idle for N cycles" without a second query.
     */
    private function presentPattern(BehaviorPattern $p, int $cycleSeq): array
    {
        $idleFor = $p->last_activation_seq !== null
            ? max(0, $cycleSeq - (int) $p->last_activation_seq)
            : null;

        return [
            'name'                       => $p->name,
            'status'                     => $p->status,
            'immune'                     => $p->immune,
            'fitness'                    => round((float) $p->fitness, 3),
            'priority'                   => (float) $p->priority,
            'confidence'                 => round((float) $p->confidence, 3),
            'plasticity'                 => (float) $p->plasticity,
            'provenance'                 => $p->provenance,
            'trigger'                    => $p->trigger,
            'intent'                     => $p->intent,
            'behavior'                   => $p->behavior,
            'constraints'                => $p->constraints,
            'lever'                      => $p->lever, // phase 2a: {dimension, delta} | null
            'forced_activation_interval' => $p->forced_activation_interval,
            'activation_count'           => (int) $p->activation_count,
            'last_activation_seq'        => $p->last_activation_seq,
            'idle_for'                   => $idleFor,
        ];
    }
}
