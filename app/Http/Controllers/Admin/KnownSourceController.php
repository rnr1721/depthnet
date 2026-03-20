<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnownSource\ClearPoolRequest;
use App\Http\Requests\Admin\KnownSource\DestroyKnownSourceRequest;
use App\Http\Requests\Admin\KnownSource\DestroyPoolItemRequest;
use App\Http\Requests\Admin\KnownSource\ReorderKnownSourceRequest;
use App\Http\Requests\Admin\KnownSource\StoreKnownSourceRequest;
use App\Http\Requests\Admin\KnownSource\StorePoolItemRequest;
use App\Http\Requests\Admin\KnownSource\UpdateKnownSourceRequest;
use App\Models\InputPoolItem;
use App\Models\PresetKnownSource;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KnownSourceController extends Controller
{
    public function __construct(
        protected InputPoolServiceInterface $poolService,
        protected PresetRegistryInterface $presetRegistry,
    ) {
    }

    public function index(Request $request)
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id'           => $preset->id,
                'name'         => $preset->name,
                'is_default'   => $preset->is_default,
                'is_pool_mode' => $preset->getInputMode() === 'pool',
            ])
            ->sortByDesc('is_default')
            ->values();

        $currentPresetId = $request->get('preset_id');
        $currentPreset   = $currentPresetId
            ? $this->presetRegistry->getPreset($currentPresetId)
            : $this->presetRegistry->getDefaultPreset();

        $isPoolMode = $currentPreset && $currentPreset->getInputMode() === 'pool';

        $sources = $isPoolMode
            ? $this->poolService->getKnownSources($currentPreset->id)->map(fn ($s) => [
                'id'            => $s->id,
                'source_name'   => $s->source_name,
                'label'         => $s->label,
                'description'   => $s->description,
                'default_value' => $s->default_value,
                'sort_order'    => $s->sort_order,
            ])->values()
            : collect();

        $poolItems = $isPoolMode
            ? $this->poolService->getItems($currentPreset->id)->map(fn ($item) => [
                'id'          => $item->id,
                'source_name' => $item->source_name,
                'content'     => $item->content,
                'updated_at'  => $item->updated_at->toIso8601String(),
            ])->values()
            : collect();

        return Inertia::render('Admin/KnownSources/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'           => $currentPreset->id,
                'name'         => $currentPreset->name,
                'is_default'   => $currentPreset->is_default,
                'is_pool_mode' => $isPoolMode,
            ] : null,
            'sources'   => $sources->values()->toArray(),
            'poolItems' => $poolItems->values()->toArray(),
        ]);
    }

    public function store(StoreKnownSourceRequest $request)
    {
        $this->poolService->addKnownSource(
            $request->getPresetId(),
            $request->getSourceName(),
            $request->getLabel(),
            $request->getDescription(),
            $request->getDefaultValue(),
        );

        return back()->with('success', __('known_source_added'));
    }

    public function update(UpdateKnownSourceRequest $request, int $id)
    {
        $source = PresetKnownSource::where('id', $id)
            ->where('preset_id', $request->getPresetId())
            ->firstOrFail();

        $source->update([
            'source_name'   => $request->getSourceName(),
            'label'         => $request->getLabel(),
            'description'   => $request->getDescription(),
            'default_value' => $request->getDefaultValue(),
        ]);

        return back()->with('success', __('known_source_updated'));
    }

    public function destroy(DestroyKnownSourceRequest $request, int $id)
    {
        $source = PresetKnownSource::where('id', $id)
            ->where('preset_id', $request->getPresetId())
            ->firstOrFail();

        $this->poolService->removeKnownSource($request->getPresetId(), $source->source_name);

        return back()->with('success', __('known_source_deleted'));
    }

    public function reorder(ReorderKnownSourceRequest $request)
    {
        $this->poolService->reorderKnownSources(
            $request->getPresetId(),
            $request->getOrderedIds(),
        );

        return back();
    }

    // -------------------------------------------------------------------------
    // Pool item management (for monitoring and manual testing)
    // -------------------------------------------------------------------------

    public function poolStore(StorePoolItemRequest $request)
    {
        $this->poolService->add(
            $request->getPresetId(),
            $request->getSourceName(),
            $request->getPoolItemContent(),
        );

        return back()->with('success', __('pool_item_added'));
    }

    public function poolDestroy(DestroyPoolItemRequest $request, int $id)
    {
        InputPoolItem::where('id', $id)
            ->where('preset_id', $request->getPresetId())
            ->delete();

        return back()->with('success', __('pool_item_deleted'));
    }

    public function poolClear(ClearPoolRequest $request)
    {
        $this->poolService->clear($request->getPresetId());

        return back()->with('success', __('pool_cleared'));
    }
}
