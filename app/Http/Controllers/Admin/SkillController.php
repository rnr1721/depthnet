<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Skill\AddSkillItemRequest;
use App\Http\Requests\Admin\Skill\DestroySkillItemRequest;
use App\Http\Requests\Admin\Skill\DestroySkillRequest;
use App\Http\Requests\Admin\Skill\ShowSkillRequest;
use App\Http\Requests\Admin\Skill\StoreSkillRequest;
use App\Http\Requests\Admin\Skill\UpdateSkillItemRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SkillController extends Controller
{
    public function __construct(
        protected SkillServiceInterface $skillService,
        protected PresetRegistryInterface $presetRegistry,
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

        $skills        = [];
        $searchResults = [];

        if ($currentPreset) {
            $skills = $this->skillService->listSkillsData($currentPreset);

            if ($request->filled('search')) {
                $searchResults = $this->skillService->searchItemsData($currentPreset, $request->get('search'));
            }
        }

        return Inertia::render('Admin/Skills/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'skills'        => $skills,
            'searchResults' => $searchResults,
            'searchQuery'   => $request->get('search', ''),
        ]);
    }

    public function show(ShowSkillRequest $request, int $skillNumber)
    {
        $currentPreset = $this->presetRegistry->getPresetOrDefault($request->getPresetId());
        $result        = $this->skillService->showSkillData($currentPreset, $skillNumber);

        return response()->json($result);
    }

    public function store(StoreSkillRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->skillService->addSkill(
            $preset,
            $request->getTitle(),
            $request->getDescription(),
            $request->getFirstItem(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function addItem(AddSkillItemRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->skillService->addItem($preset, $request->getSkillNumber(), $request->getContent());

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function updateItem(UpdateSkillItemRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->skillService->updateItem(
            $preset,
            $request->getSkillNumber(),
            $request->getItemNumber(),
            $request->getContent(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function destroyItem(DestroySkillItemRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->skillService->deleteItem(
            $preset,
            $request->getSkillNumber(),
            $request->getItemNumber(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function destroy(DestroySkillRequest $request, int $skillNumber)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->skillService->deleteSkill($preset, $skillNumber);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }
}
