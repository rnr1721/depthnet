<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Goal\ClearGoalsRequest;
use App\Http\Requests\Admin\Goal\DestroyGoalRequest;
use App\Http\Requests\Admin\Goal\IndexGoalRequest;
use App\Http\Requests\Admin\Goal\SetGoalStatusRequest;
use App\Http\Requests\Admin\Goal\ShowGoalRequest;
use App\Http\Requests\Admin\Goal\StoreGoalRequest;
use App\Http\Requests\Admin\Goal\StoreProgressRequest;
use Inertia\Inertia;

class GoalController extends Controller
{
    public function __construct(
        protected GoalServiceInterface $goalService,
        protected PresetRegistryInterface $presetRegistry,
    ) {
    }

    public function index(IndexGoalRequest $request)
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id'         => $preset->id,
                'name'       => $preset->name,
                'is_default' => $preset->is_default,
            ])
            ->sortByDesc('is_default')
            ->values();

        $currentPresetId = $request->getPresetId() ?? $this->presetRegistry->getDefaultPreset()->id;
        $currentPreset   = $this->presetRegistry->getPresetOrDefault($currentPresetId);

        $goals = [];
        if ($currentPreset) {
            $goals = $this->getStructuredGoals($currentPreset);
        }

        return Inertia::render('Admin/Goals/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'goals'         => $goals,
            'statusFilter'  => $request->getStatusFilter(),
        ]);
    }

    public function show(ShowGoalRequest $request, int $goalNumber)
    {
        $currentPreset = $this->presetRegistry->getPresetOrDefault($request->getPresetId());
        $result        = $this->goalService->showGoal($currentPreset, $goalNumber);

        return response()->json($result);
    }

    public function store(StoreGoalRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->goalService->addGoal(
            $preset,
            $request->getTitle(),
            $request->getMotivation(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function storeProgress(StoreProgressRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->goalService->addProgress(
            $preset,
            $request->getGoalNumber(),
            $request->getProgressContent(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function setStatus(SetGoalStatusRequest $request, int $goalNumber)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->goalService->setStatus($preset, $goalNumber, $request->getStatus());

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function destroy(DestroyGoalRequest $request, int $goalNumber)
    {
        // GoalService does not have a deleteGoal method — we go via the model directly
        // or you can add deleteGoal() to the interface. Using clear-scoped delete here:
        $preset = $this->presetRegistry->getPreset($request->getPresetId());

        // Fetch goal by number then delete
        $deleted = $this->deleteGoalByNumber($preset, $goalNumber);

        if ($deleted) {
            return back()->with('success', __('gm.goal_deleted'));
        }

        return back()->with('error', __('gm.goal_not_found'));
    }

    public function clear(ClearGoalsRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $this->goalService->clear($preset);

        return back()->with('success', __('gm.goals_cleared'));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return structured goal data for the Vue page.
     * GoalService::listGoals returns a formatted string — we need the raw model data.
     */
    private function getStructuredGoals($preset): array
    {
        // Re-use the Goal model via the service's underlying query
        // Since GoalService doesn't expose raw data, we inject the model via the registry
        // or resolve it from the container. Adjust the namespace if needed.
        $goals = \App\Models\Goal::forPreset($preset->id)
            ->ordered()
            ->with(['progress' => fn ($q) => $q->orderBy('created_at')])
            ->get()
            ->values();

        // number = 1-based index in the ordered list — the same numbering every
        // GoalService command and the agent resolve. NOT `position`: positions
        // keep gaps after a goal is deleted, indexes don't.
        return $goals->map(fn ($goal, $index) => [
            'id'         => $goal->id,
            'number'     => $index + 1,
            'title'      => $goal->title,
            'motivation' => $goal->motivation,
            'status'     => $goal->status,
            'focused'    => $goal->isFocused(),
            'progress'   => $goal->progress->map(fn ($p) => [
                'id'         => $p->id,
                'content'    => $p->content,
                'created_at' => $p->created_at->format('Y-m-d H:i'),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Delete a goal by its display number (1-based index in the ordered list).
     * If the goal was in focus, the focus goes with it — the marker lives on the row.
     */
    private function deleteGoalByNumber($preset, int $number): bool
    {
        if ($number < 1) {
            return false;
        }

        $goal = \App\Models\Goal::forPreset($preset->id)
            ->ordered()
            ->skip($number - 1)
            ->first();

        if (!$goal) {
            return false;
        }

        $goal->delete();
        return true;
    }
}
