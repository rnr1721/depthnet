<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Journal\JournalServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Journal\{
    StoreJournalEntryRequest,
    DeleteJournalEntryRequest,
    ClearJournalRequest,
    SearchJournalRequest,
};
use Illuminate\Http\Request;
use Inertia\Inertia;

class JournalController extends Controller
{
    public function __construct(
        protected JournalServiceInterface  $journalService,
        protected PresetRegistryInterface  $presetRegistry,
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

        $perPage       = max(10, min(100, (int) $request->get('per_page', 20)));
        $searchQuery   = $request->get('search', '');
        $filterType    = $request->get('type', '');
        $filterOutcome = $request->get('outcome', '');

        $entries       = collect();
        $searchResults = collect();
        $stats         = [];

        if ($currentPreset) {
            // Stats
            $total   = \App\Models\JournalEntry::forPreset($currentPreset->id)->count();
            $byType  = \App\Models\JournalEntry::forPreset($currentPreset->id)
                ->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            $stats = [
                'total'   => $total,
                'by_type' => $byType,
            ];

            if ($searchQuery) {
                $result = $this->journalService->search($currentPreset, $searchQuery, 50);
                // search() returns formatted string — for UI we need raw entries
                $rawEntries = $this->journalService->searchEntries($currentPreset, $searchQuery, 50);
                $searchResults = collect($rawEntries)->map(fn ($e) => $this->formatEntry($e));
            } else {
                $query = \App\Models\JournalEntry::forPreset($currentPreset->id)
                    ->orderBy('recorded_at', 'desc');

                if ($filterType) {
                    $query->where('type', $filterType);
                }
                if ($filterOutcome) {
                    $query->where('outcome', $filterOutcome);
                }

                $paginated = $query->paginate($perPage)->withQueryString();
                $entries   = $paginated->getCollection()->map(fn ($e) => $this->formatEntry($e));
            }
        }

        return Inertia::render('Admin/Journal/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset ? [
                'id'         => $currentPreset->id,
                'name'       => $currentPreset->name,
                'is_default' => $currentPreset->is_default,
            ] : null,
            'entries'        => $entries,
            'searchResults'  => $searchResults,
            'stats'          => $stats,
            'pagination'     => isset($paginated) ? $paginated->toArray() : null,
            'searchQuery'    => $searchQuery,
            'filterType'     => $filterType,
            'filterOutcome'  => $filterOutcome,
            'perPage'        => $perPage,
            'types'          => \App\Services\Agent\Journal\JournalService::TYPES,
            'outcomes'       => \App\Services\Agent\Journal\JournalService::OUTCOMES,
        ]);
    }

    public function store(StoreJournalEntryRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->journalService->addEntry($preset, $request->getJournalEntryContent());

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function destroy(DeleteJournalEntryRequest $request, int $entryId)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->journalService->delete($preset, $entryId);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function clear(ClearJournalRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->validated('preset_id'));
        $result = $this->journalService->clear($preset);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function search(SearchJournalRequest $request)
    {
        return redirect()->route('admin.journal.index', [
            'preset_id' => $request->validated('preset_id'),
            'search'    => $request->getSearchQuery(),
        ]);
    }

    // -------------------------------------------------------------------------

    private function formatEntry(\App\Models\JournalEntry $entry): array
    {
        return [
            'id'          => $entry->id,
            'recorded_at' => $entry->recorded_at,
            'type'        => $entry->type,
            'summary'     => $entry->summary,
            'details'     => $entry->details,
            'outcome'     => $entry->outcome,
        ];
    }
}
