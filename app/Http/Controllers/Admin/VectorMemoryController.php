<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VectorMemory\{
    StoreVectorMemoryRequest,
    UpdateImportanceRequest,
    SearchVectorMemoryRequest,
    ImportVectorMemoryRequest,
    ExportVectorMemoryRequest,
    DeleteVectorMemoryRequest,
    ClearVectorMemoryRequest,
    StatsVectorMemoryRequest,
};
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for managing AI preset vector memory items
 * Provides CRUD operations and semantic search interface
 */
class VectorMemoryController extends Controller
{
    private array $config;

    public function __construct(
        protected VectorMemoryServiceInterface $vectorMemoryService,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginRegistryInterface $pluginRegistry
    ) {
        $this->config = $pluginRegistry->get('vectormemory')->getConfig();
    }

    /**
     * Display vector memory management interface
     */
    public function index(Request $request)
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

        $vectorMemories = [];
        $memoryStats = [];
        $searchResults = [];

        if ($currentPreset) {
            $vectorMemories = $this->vectorMemoryService->getVectorMemories($currentPreset, 50)->map(function ($memory) {
                return [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'keywords' => $memory->keywords ?? [],
                    'importance' => $memory->importance,
                    'vector_size' => count($memory->tfidf_vector ?? []),
                    'created_at' => $memory->created_at,
                    'truncated_content' => $memory->truncated_content,
                    'age_in_days' => $memory->age_in_days,
                ];
            });

            $memoryStats = $this->vectorMemoryService->getVectorMemoryStats($currentPreset, $this->config);

            // If there's a search query, perform search
            if ($request->filled('search')) {
                $searchResult = $this->vectorMemoryService->searchVectorMemories(
                    $currentPreset,
                    $request->get('search'),
                    $this->config
                );

                if ($searchResult['success']) {
                    $searchResults = collect($searchResult['results'])->map(function ($result) {
                        $memory = $result['memory'];
                        return [
                            'id' => $memory->id,
                            'content' => $memory->content,
                            'keywords' => $memory->keywords ?? [],
                            'importance' => $memory->importance,
                            'vector_size' => count($memory->tfidf_vector ?? []),
                            'created_at' => $memory->created_at,
                            'similarity' => $result['similarity'],
                            'similarity_percent' => round($result['similarity'] * 100, 1),
                        ];
                    });
                }
            }
        }

        return Inertia::render('Admin/VectorMemory/Index', [
            'presets' => $presets,
            'currentPreset' => [
                'id' => $currentPreset->id,
                'name' => $currentPreset->name,
                'is_default' => $currentPreset->is_default
            ],
            'vectorMemories' => $vectorMemories,
            'memoryStats' => $memoryStats,
            'searchResults' => $searchResults,
            'config' => $this->config,
            'searchQuery' => $request->get('search', ''),
        ]);
    }

    /**
     * Store new vector memory
     */
    public function store(StoreVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->vectorMemoryService->storeVectorMemory(
            $preset,
            $request->getValidatedContent(),
            $this->config
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Update vector memory importance
     */
    public function updateImportance(UpdateImportanceRequest $request, int $memoryId)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->vectorMemoryService->updateVectorMemoryImportance(
            $preset,
            $memoryId,
            $request->getImportance()
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Delete vector memory
     */
    public function destroy(DeleteVectorMemoryRequest $request, int $memoryId)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->vectorMemoryService->deleteVectorMemory($preset, $memoryId);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Clear all vector memories
     */
    public function clear(ClearVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->vectorMemoryService->clearVectorMemories($preset);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Search vector memories by semantic similarity
     */
    public function search(SearchVectorMemoryRequest $request)
    {
        return redirect()->route('admin.vector-memory.index', [
            'preset_id' => $request->validated('preset_id'),
            'search' => $request->getSearchQuery()
        ]);
    }

    /**
     * Export vector memories as JSON
     */
    public function export(ExportVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->vectorMemoryService->exportVectorMemories($preset);

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        return response($result['content'])
            ->header('Content-Type', $result['headers']['Content-Type'])
            ->header('Content-Disposition', $result['headers']['Content-Disposition']);
    }

    /**
     * Import vector memories from file or content
     */
    public function import(ImportVectorMemoryRequest $request)
    {
        try {
            $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
            $importData = $request->getImportContent();

            $result = $this->vectorMemoryService->importVectorMemories(
                $preset,
                $importData['content'],
                $importData['is_json'],
                $importData['replace_existing'],
                $this->config
            );

            if ($result['success']) {
                $action = $importData['replace_existing'] ? 'replaced' : 'imported';
                $message = "Vector memories {$action} successfully. Added: {$result['success_count']}";
                if ($result['error_count'] > 0) {
                    $message .= ", Errors: {$result['error_count']}";
                }
                return back()->with('success', $message);
            }

            return back()->with('error', $result['message']);

        } catch (\Exception $e) {
            return back()->with('error', 'Error importing content: ' . $e->getMessage());
        }
    }

    /**
     * Get memory statistics for AJAX requests
     */
    public function stats(StatsVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $stats = $this->vectorMemoryService->getVectorMemoryStats($preset, $this->config);

        return response()->json($stats);
    }

}
