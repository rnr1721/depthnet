<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Person\AddFactRequest;
use App\Http\Requests\Admin\Person\ClearAllPeopleRequest;
use App\Http\Requests\Admin\Person\DeleteFactRequest;
use App\Http\Requests\Admin\Person\ForgetPersonRequest;
use App\Http\Requests\Admin\Person\IndexPersonRequest;
use Inertia\Inertia;

class PersonController extends Controller
{
    public function __construct(
        protected PersonMemoryServiceInterface $personMemoryService,
        protected PresetRegistryInterface $presetRegistry,
    ) {
    }

    public function index(IndexPersonRequest $request)
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

        $people = [];
        if ($currentPreset) {
            $people = $this->personMemoryService->getStructuredPeople($currentPreset);
        }

        return Inertia::render('Admin/PersonMemory/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'people'        => $people,
        ]);
    }

    public function addFact(AddFactRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->personMemoryService->addFact(
            $preset,
            $request->getPersonName(),
            $request->getPersonContent(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function deleteFact(DeleteFactRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->personMemoryService->deleteFact(
            $preset,
            $request->getPersonName(),
            $request->getFactNumber(),
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function forgetPerson(ForgetPersonRequest $request, string $personName)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $result = $this->personMemoryService->forgetPerson($preset, $personName);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function clearAll(ClearAllPeopleRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());

        $this->personMemoryService->clearAll($preset);

        return back()->with('success', __('pm.all_cleared'));
    }

}
