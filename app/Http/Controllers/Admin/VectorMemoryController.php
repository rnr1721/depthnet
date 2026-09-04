<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginManagerFactoryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryFactoryInterface;
use App\Contracts\Agent\VectorMemory\VectorMemoryServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VectorMemory\{
    StoreVectorMemoryRequest,
    UpdateImportanceRequest,
    SearchVectorMemoryRequest,
    ImportVectorMemoryRequest,
    ExportVectorMemoryRequest,
    DeleteVectorMemoryRequest,
    ClearVectorMemoryRequest,
    PurgeDomainRequest,
    StatsVectorMemoryRequest,
};
use App\Models\AiPreset;
use App\Models\VectorMemory;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for managing AI preset vector memory items
 * Provides CRUD operations, semantic search and domain management
 */
class VectorMemoryController extends Controller
{
    public function __construct(
        protected VectorMemoryFactoryInterface $vectorMemoryFactory,
        protected PresetRegistryInterface $presetRegistry,
        protected PluginManagerFactoryInterface $pluginManagerFactory
    ) {
    }

    /**
     * Resolve the vectormemory plugin's resolved config for a given preset.
     */
    protected function getVectorMemoryConfig(AiPreset $preset): array
    {
        $info = $this->pluginManagerFactory->get()->getPluginInfoForPreset('vectormemory', $preset);
        if (empty($info)) {
            $info = $this->pluginManagerFactory->get()->getPluginInfoForPreset('knowledge', $preset);
        }
        return $info['current_config'] ?? [];
    }

    /**
     * Display vector memory management interface with pagination
     *
     * Optional query params:
     *   - preset_id: which preset to view
     *   - domain:    filter records by a single domain name; null/empty = all domains
     *   - search:    semantic search query (respects domain filter via $config['domains'])
     *   - per_page:  pagination page size
     */
    public function index(Request $request)
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($preset) => [
                'id' => $preset->id,
                'name' => $preset->name,
                'is_default' => $preset->is_default,
            ])
            ->sortByDesc('is_default')
            ->values();

        $vectorMemoryService = $this->vectorMemoryFactory->make();

        $currentPresetId = $request->get('preset_id', $this->presetRegistry->getDefaultPreset()->id);
        $currentPreset = $this->presetRegistry->getPresetOrDefault($currentPresetId);

        $perPage = max(10, min(100, (int) $request->get('per_page', 20)));

        $domainFilter = trim((string) $request->get('domain', ''));
        $domainFilter = $domainFilter !== '' ? mb_strtolower($domainFilter) : null;

        $vectorMemories = collect();
        $memoryStats = [];
        $searchResults = [];
        $config = [];
        $paginatedMemories = null;
        $domains = [];

        if ($currentPreset) {
            $config = $this->getVectorMemoryConfig($currentPreset);

            // Live domain registry for this preset — used by the filter selector and modals
            $domains = $vectorMemoryService->listDomains($currentPreset);

            $paginatedMemories = $this->getPaginatedMemoriesWithDomainFilter(
                $vectorMemoryService,
                $currentPreset,
                $perPage,
                $domainFilter
            );

            $vectorMemories = $paginatedMemories->map(function ($memory) {
                return [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'keywords' => $memory->keywords ?? [],
                    'importance' => $memory->importance,
                    'vector_size' => count($memory->tfidf_vector ?? []),
                    'has_embedding' => !empty($memory->embedding),
                    'domain' => $memory->domain ?? VectorMemory::DEFAULT_DOMAIN,
                    'created_at' => $memory->created_at,
                    'truncated_content' => $memory->truncated_content,
                    'age_in_days' => $memory->age_in_days,
                ];
            });

            $memoryStats = $vectorMemoryService->getVectorMemoryStats($currentPreset, $config);
            // Augment stats with domain count for the header strip
            $memoryStats['domain_count'] = count($domains);

            if ($request->filled('search')) {
                // Inject domain filter into search config so the service applies it
                $searchConfig = $config;
                if ($domainFilter !== null) {
                    $searchConfig['domains'] = [$domainFilter];
                }

                $searchResult = $vectorMemoryService->searchVectorMemories(
                    $currentPreset,
                    $request->get('search'),
                    $searchConfig
                );

                if ($searchResult['success']) {
                    $searchResults = collect($searchResult['results'])->map(function ($result) {
                        $memory = $result['document'] ?? $result['memory'];
                        return [
                            'id' => $memory->id,
                            'content' => $memory->getTextContent(),
                            'keywords' => $memory->keywords ?? [],
                            'importance' => $memory->importance,
                            'vector_size' => count($memory->getTfIdfVector()),
                            'has_embedding' => !empty($memory->embedding ?? null),
                            'domain' => $memory->domain ?? VectorMemory::DEFAULT_DOMAIN,
                            'created_at' => $memory->getCreatedAt(),
                            'similarity' => $result['similarity'],
                            'similarity_percent' => round($result['similarity'] * 100, 1),
                        ];
                    });
                }
            }
        }

        $defaultDomain = $config['default_domain'] ?? VectorMemory::DEFAULT_DOMAIN;

        return Inertia::render('Admin/VectorMemory/Index', [
            'presets' => $presets,
            'currentPreset' => [
                'id' => $currentPreset->id,
                'name' => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ],
            'vectorMemories' => $vectorMemories,
            'pagination' => $paginatedMemories?->toArray(),
            'memoryStats' => $memoryStats,
            'searchResults' => $searchResults,
            'config' => $config,
            'searchQuery' => $request->get('search', ''),
            'perPage' => $perPage,
            'domains' => $domains,
            'currentDomain' => $domainFilter,
            'defaultDomain' => $defaultDomain,
        ]);
    }

    /**
     * Build paginated query with optional domain filter applied directly on the model.
     *
     * VectorMemoryServiceInterface::getPaginatedVectorMemories doesn't accept a
     * domain filter yet — to keep contract changes minimal we apply the filter
     * here via the same VectorMemory model that the service uses internally.
     */
    private function getPaginatedMemoriesWithDomainFilter(
        VectorMemoryServiceInterface $vectorMemoryService,
        AiPreset $preset,
        int $perPage,
        ?string $domainFilter,
    ) {
        if ($domainFilter === null) {
            return $vectorMemoryService->getPaginatedVectorMemories($preset, $perPage);
        }

        return VectorMemory::query()
            ->where('preset_id', $preset->id)
            ->where('domain', $domainFilter)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Store new vector memory.
     *
     * Accepts optional 'domain' from the request. If empty or absent, the
     * service falls back to plugin config's default_domain.
     */
    public function store(StoreVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $vectorMemoryService = $this->vectorMemoryFactory->make();

        $config = $this->getVectorMemoryConfig($preset);
        $domain = $request->getValidatedDomain();
        if ($domain !== null) {
            $config['domain'] = $domain;
        }

        $result = $vectorMemoryService->storeVectorMemory(
            $preset,
            $request->getValidatedContent(),
            $config
        );

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    /**
     * Update vector memory importance
     */
    public function updateImportance(UpdateImportanceRequest $request, int $memoryId)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $vectorMemoryService = $this->vectorMemoryFactory->make();
        $result = $vectorMemoryService->updateVectorMemoryImportance(
            $preset,
            $memoryId,
            $request->getImportance()
        );

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    /**
     * Delete vector memory
     */
    public function destroy(DeleteVectorMemoryRequest $request, int $memoryId)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $vectorMemoryService = $this->vectorMemoryFactory->make();
        $result = $vectorMemoryService->deleteVectorMemory($preset, $memoryId);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    /**
     * Clear all vector memories
     */
    public function clear(ClearVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $vectorMemoryService = $this->vectorMemoryFactory->make();
        $result = $vectorMemoryService->clearVectorMemories($preset);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    /**
     * Permanently delete all records of a single domain.
     * Admin-side operation; refuses to purge the default domain.
     */
    public function purgeDomain(PurgeDomainRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $vectorMemoryService = $this->vectorMemoryFactory->make();

        $config = $this->getVectorMemoryConfig($preset);
        $defaultDomain = $config['default_domain'] ?? VectorMemory::DEFAULT_DOMAIN;

        $domain = $request->getValidatedDomain();

        if ($domain === $defaultDomain) {
            return back()->with(
                'error',
                "The default domain '{$defaultDomain}' cannot be purged from admin. "
                . "Use 'Clear all memories' if you really need to wipe everything."
            );
        }

        $deleted = $vectorMemoryService->purgeDomain($preset, $domain);

        return $deleted === 0
            ? back()->with('success', "Domain '{$domain}' had no records — nothing to purge.")
            : back()->with('success', "Purged domain '{$domain}': {$deleted} record(s) permanently deleted.");
    }

    /**
     * Search vector memories by semantic similarity.
     * Domain filter is carried via the URL and re-applied inside index() above.
     */
    public function search(SearchVectorMemoryRequest $request)
    {
        $params = [
            'preset_id' => $request->validated('preset_id'),
            'search'    => $request->getSearchQuery(),
        ];

        $domain = $request->getValidatedDomain();
        if ($domain !== null) {
            $params['domain'] = $domain;
        }

        return redirect()->route('admin.vector-memory.index', $params);
    }

    /**
     * Export vector memories as JSON
     */
    public function export(ExportVectorMemoryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $vectorMemoryService = $this->vectorMemoryFactory->make();
        $result = $vectorMemoryService->exportVectorMemories($preset);

        if (!$result['success']) {
            return back()->with('error', $result['message']);
        }

        return response($result['content'])
            ->header('Content-Type', $result['headers']['Content-Type'])
            ->header('Content-Disposition', $result['headers']['Content-Disposition']);
    }

    /**
     * Import vector memories from file or content.
     *
     * Accepts optional 'target_domain' which, when set, OVERRIDES per-record
     * domain values from JSON v3 exports and routes everything into the same
     * domain. When empty, JSON v3 records keep their original domain; plain
     * text imports fall back to the plugin's default_domain.
     */
    public function import(ImportVectorMemoryRequest $request)
    {
        try {
            $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
            $importData = $request->getImportContent();

            $config = $this->getVectorMemoryConfig($preset);
            $targetDomain = $request->getValidatedTargetDomain();
            if ($targetDomain !== null) {
                // Used by storeWithMeta as a forced default when meta doesn't carry one
                // AND, importantly, we override per-record meta below if explicitly asked.
                $config['default_domain'] = $targetDomain;
                $config['force_domain']   = $targetDomain;
            }

            $vectorMemoryService = $this->vectorMemoryFactory->make();
            $result = $vectorMemoryService->importVectorMemories(
                $preset,
                $importData['content'],
                $importData['is_json'],
                $importData['replace_existing'],
                $config
            );

            if ($result['success']) {
                $action = $importData['replace_existing'] ? 'replaced' : 'imported';
                $message = "Vector memories {$action} successfully. Added: {$result['success_count']}";
                if ($result['error_count'] > 0) {
                    $message .= ", Errors: {$result['error_count']}";
                }
                if ($targetDomain !== null) {
                    $message .= " (forced into domain '{$targetDomain}')";
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
        $vectorMemoryService = $this->vectorMemoryFactory->make();
        $stats = $vectorMemoryService->getVectorMemoryStats($preset, $this->getVectorMemoryConfig($preset));

        $stats['domain_count'] = count($vectorMemoryService->listDomains($preset));

        return response()->json($stats);
    }
}
