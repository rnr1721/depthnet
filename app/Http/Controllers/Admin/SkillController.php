<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Skills\SkillLibraryServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Skill\AddSkillItemRequest;
use App\Http\Requests\Admin\Skill\DestroySkillItemRequest;
use App\Http\Requests\Admin\Skill\DestroySkillRequest;
use App\Http\Requests\Admin\Skill\LibrarySkillRequest;
use App\Http\Requests\Admin\Skill\ShowSkillRequest;
use App\Http\Requests\Admin\Skill\StoreSkillRequest;
use App\Http\Requests\Admin\Skill\UpdateSkillItemRequest;
use App\Http\Requests\Admin\Skill\UpdateSkillRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SkillController extends Controller
{
    public function __construct(
        protected SkillServiceInterface $skillService,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginRegistryInterface $pluginRegistry,
        protected SkillLibraryServiceInterface $library,
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
            'availablePluginNames' => $this->pluginRegistry->getAvailablePluginNames(),
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
            $request->getTools(),
            $request->getIsExperience(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function update(UpdateSkillRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->skillService->updateSkill(
            $preset,
            $request->getSkillNumber(),
            $request->getFields(),
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

    // ── Library (standard skills from {shared}/skills) ───────────────────────

    /**
     * JSON for the "Connect from library" modal: every library folder with its
     * parse status, plus this preset's state (attached as #N, stale) and orphans.
     */
    public function library(LibrarySkillRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());

        return response()->json(
            ['root' => $this->library->libraryRoot()] + $this->library->listForPreset($preset)
        );
    }

    public function attachLibrary(LibrarySkillRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $name   = $request->getName();

        if ($name === null) {
            return back()->with('error', 'Library skill name is required.');
        }

        $result = $this->library->attach($preset, $name);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Re-import one guest skill (skill_number) or every changed one (no number).
     */
    public function refreshLibrary(LibrarySkillRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $number = $request->getSkillNumber();

        if ($number !== null) {
            $r = $this->library->refresh($preset, $number, $request->getForce());
            $msg = "Skill #{$number}: {$r['message']}";
            return back()->with($r['success'] ? 'success' : 'error', $msg);
        }

        $r = $this->library->refreshAll($preset);
        $parts = [];
        $parts[] = $r['refreshed'] ? 'Re-imported: #' . implode(', #', $r['refreshed']) : 'Nothing changed';
        if ($r['missing']) {
            $parts[] = 'library folder missing for #' . implode(', #', $r['missing']);
        }
        foreach ($r['failed'] as $n => $why) {
            $parts[] = "#{$n} failed: {$why}";
        }

        return back()->with($r['failed'] ? 'error' : 'success', implode('. ', $parts) . '.');
    }
}
