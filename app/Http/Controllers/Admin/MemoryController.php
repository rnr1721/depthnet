<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Memory\MemoryExporterInterface;
use App\Contracts\Agent\Memory\MemoryImporterInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Memory\ImportMemoryRequest;
use App\Http\Requests\Admin\Memory\MemoryActionRequest;
use App\Http\Requests\Admin\Memory\SearchMemoryRequest;
use App\Http\Requests\Admin\Memory\StoreMemoryItemRequest;
use App\Http\Requests\Admin\Memory\UpdateMemoryItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing AI preset memory items
 * Provides CRUD operations and memory management interface
 */
class MemoryController extends Controller
{
    private array $config;

    public function __construct(
        protected MemoryServiceInterface $memoryService,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginRegistryInterface $pluginRegistry,
        protected MemoryImporterInterface $memoryImporter,
        protected MemoryExporterInterface $memoryExporter
    ) {
        $this->config = $pluginRegistry->get('memory')->getConfig();
    }

    /**
     * Display memory management interface with pagination
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request): Response
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id' => $preset->id,
                'name' => $preset->name,
                'is_default' => $preset->is_default
            ])
            ->sortByDesc('is_default')
            ->values();

        $currentPresetId = $request->get('preset_id', $this->presetRegistry->getDefaultPreset()->id);
        $currentPreset = $this->presetRegistry->getPresetOrDefault($currentPresetId);
        $searchQuery = $request->get('search', '');
        $perPage = max(10, min(100, (int) $request->get('per_page', 20)));

        $memoryItems = collect();
        $memoryStats = [];

        if ($currentPreset) {
            // If we have a search query, perform paginated search
            if (!empty($searchQuery)) {
                $paginatedItems = $this->memoryService->searchMemoryPaginated($currentPreset, $searchQuery, $perPage);
            } else {
                $paginatedItems = $this->memoryService->getPaginatedMemoryItems($currentPreset, $perPage);
            }

            $memoryItems = $paginatedItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'content' => $item->content,
                    'position' => $item->position,
                ];
            });

            $memoryStats = $this->memoryService->getMemoryStats($currentPreset, $this->config);
        }

        return Inertia::render('Admin/Memory/Index', [
            'presets' => $presets,
            'currentPreset' => [
                'id' => $currentPreset->id,
                'name' => $currentPreset->name,
                'is_default' => $currentPreset->is_default
            ],
            'memoryItems' => $memoryItems,
            'pagination' => $currentPreset ? $paginatedItems->toArray() : null,
            'memoryStats' => $memoryStats,
            'config' => $this->config,
            'searchQuery' => $searchQuery,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Add new memory item
     *
     * @param StoreMemoryItemRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreMemoryItemRequest $request): RedirectResponse
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);
        $result = $this->memoryService->addMemoryItem(
            $preset,
            $request->content,
            $this->config
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Update existing memory item
     *
     * @param UpdateMemoryItemRequest $request
     * @param int $itemId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateMemoryItemRequest $request, int $itemId): RedirectResponse
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);
        $items = $this->memoryService->getMemoryItems($preset);

        $item = $items->where('id', $itemId)->first();
        if (!$item) {
            return back()->with('error', 'Memory item not found.');
        }

        // Update the item directly
        $item->update(['content' => $request->content]);

        return back()->with('success', 'Memory item updated successfully.');
    }

    /**
     * Delete memory item
     *
     * @param MemoryActionRequest $request
     * @param int $itemNumber
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(MemoryActionRequest $request, int $itemNumber): RedirectResponse
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);
        $result = $this->memoryService->deleteMemoryItem(
            $preset,
            $itemNumber,
            $this->config
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Clear all memory items
     *
     * @param MemoryActionRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clear(MemoryActionRequest $request): RedirectResponse
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);
        $result = $this->memoryService->clearMemory(
            $preset,
            $this->config
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Search memory items - redirect to index with search parameters
     *
     * @param SearchMemoryRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function search(SearchMemoryRequest $request): RedirectResponse
    {
        // Redirect to index with search parameters in URL
        return redirect()->route('admin.memory.index', [
            'preset_id' => $request->preset_id,
            'search' => $request->search_term,
        ]);
    }

    /**
     * Export memory as text file
     *
     * @param MemoryActionRequest $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function export(MemoryActionRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);
        $formatted = $this->memoryService->getFormattedMemory($preset);

        if (empty($formatted)) {
            return back()->with('error', 'No memory content to export.');
        }

        return $this->memoryExporter->export($preset, $formatted);
    }

    /**
     * Import memory from text file or content
     *
     * @param ImportMemoryRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function import(ImportMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);
        $result = $this->memoryImporter->import($preset, $request, $this->config);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Get memory statistics for AJAX requests
     *
     * @param MemoryActionRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats(MemoryActionRequest $request): JsonResponse
    {
        $preset = $this->presetRegistry->getPreset($request->preset_id);

        $stats = $this->memoryService->getMemoryStats($preset, $this->config);

        return response()->json($stats);
    }

}
