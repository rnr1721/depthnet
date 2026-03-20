<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Workspace\WorkspaceServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Workspace\ClearWorkspaceRequest;
use App\Http\Requests\Admin\Workspace\DestroyWorkspaceRequest;
use App\Http\Requests\Admin\Workspace\IndexWorkspaceRequest;
use App\Http\Requests\Admin\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Admin\Workspace\UpdateWorkspaceRequest;
use Inertia\Inertia;

class WorkspaceController extends Controller
{
    public function __construct(
        protected WorkspaceServiceInterface $workspaceService,
        protected PresetRegistryInterface $presetRegistry,
    ) {
    }

    public function index(IndexWorkspaceRequest $request)
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

        $entries = [];
        if ($currentPreset) {
            $raw = $this->workspaceService->all($currentPreset);
            foreach ($raw as $key => $value) {
                $entries[] = ['key' => $key, 'value' => $value];
            }
        }

        return Inertia::render('Admin/Workspace/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'entries'       => $entries,
        ]);
    }

    public function store(StoreWorkspaceRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $this->workspaceService->set($preset, $request->getKey(), $request->getValue());

        return back()->with('success', 'Workspace entry created.');
    }

    public function update(UpdateWorkspaceRequest $request, string $key)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $this->workspaceService->set($preset, $key, $request->getValue());

        return back()->with('success', 'Workspace entry updated.');
    }

    public function destroy(DestroyWorkspaceRequest $request, string $key)
    {
        $preset  = $this->presetRegistry->getPreset($request->getPresetId());
        $deleted = $this->workspaceService->delete($preset, $key);

        if ($deleted) {
            return back()->with('success', 'Workspace entry deleted.');
        }

        return back()->with('error', 'Workspace entry not found.');
    }

    public function clear(ClearWorkspaceRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $this->workspaceService->clear($preset);

        return back()->with('success', 'Workspace cleared.');
    }
}
