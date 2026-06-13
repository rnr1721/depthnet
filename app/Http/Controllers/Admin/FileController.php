<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\File\DestroyFileRequest;
use App\Http\Requests\Admin\File\DownloadFileRequest;
use App\Http\Requests\Admin\File\ReprocessFileRequest;
use App\Http\Requests\Admin\File\SearchFileRequest;
use App\Http\Requests\Admin\File\StoreFileRequest;
use App\Services\Agent\FileStorage\FileQueryService;
use App\Services\Agent\FileStorage\FileService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Document Manager admin controller — thin HTTP layer only.
 * All queries → FileQueryService. All pipeline logic → FileService.
 */
class FileController extends Controller
{
    public function __construct(
        protected FileService             $fileService,
        protected FileQueryService        $fileQueryService,
        protected PresetRegistryInterface $presetRegistry,
    ) {
    }

    public function index(Request $request)
    {
        $presets = $this->presetRegistry->getActivePresets()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'is_default' => $p->is_default])
            ->sortByDesc('is_default')
            ->values();

        $currentPresetId = (int) $request->get('preset_id', $this->presetRegistry->getDefaultPreset()->id);
        $currentPreset   = $this->presetRegistry->getPresetOrDefault($currentPresetId);

        $perPage       = max(10, min(100, (int) $request->get('per_page', 20)));
        $files         = collect();
        $searchResults = collect();
        $stats         = [];
        $paginated     = null;

        if ($currentPreset) {
            /** @var \Illuminate\Pagination\LengthAwarePaginator|null $paginated */
            $paginated = $this->fileQueryService->paginateForPreset($currentPreset->id, $perPage);
            $files     = collect($paginated->items())->map(fn ($f) => $this->fileQueryService->format($f));
            $stats     = $this->fileQueryService->statsForPreset($currentPreset->id);

            if ($request->filled('search')) {
                $result = $this->fileService->search(
                    preset:    $currentPreset,
                    query:     $request->get('search'),
                    limit:     10,
                    threshold: 0.15,
                );

                if ($result['success'] && !empty($result['results'])) {
                    $searchResults = collect($result['results'])->map(fn ($r) => [
                        'file_id'     => $r['chunk']->file_id,
                        'file_name'   => $r['chunk']->file->original_name,
                        'chunk_index' => $r['chunk']->chunk_index,
                        'content'     => mb_substr($r['chunk']->content, 0, 300),
                        'similarity'  => round($r['similarity'] * 100, 1),
                        'source'      => $r['source'],
                    ]);
                }
            }
        }

        return Inertia::render('Admin/Documents/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset
                ? ['id' => $currentPreset->id, 'name' => $currentPreset->name, 'is_default' => $currentPreset->is_default]
                : null,
            'files'         => $files,
            'pagination'    => $paginated?->toArray(),
            'stats'         => $stats,
            'searchResults' => $searchResults,
            'searchQuery'   => $request->get('search', ''),
            'perPage'       => $perPage,
        ]);
    }

    public function store(StoreFileRequest $request)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());

        try {
            $file = $this->fileService->store(
                upload:      $request->file('file'),
                preset:      $preset,
                driver:      $request->getDriver(),
                scope:       $request->getScope(),
                projectSlug: $request->getProjectSlug(),
            );

            return back()->with(
                'success',
                "File \"{$file->original_name}\" uploaded and processed ({$file->chunk_count} chunks)."
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Upload failed: ' . $e->getMessage());
        }
    }

    public function destroy(DestroyFileRequest $request, int $fileId)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $file   = $this->fileQueryService->findForPreset($fileId, $preset->id);

        if (!$file) {
            return back()->with('error', "File #{$fileId} not found.");
        }

        $name = $file->original_name;

        return $this->fileService->delete($file)
            ? back()->with('success', "File \"{$name}\" deleted.")
            : back()->with('error', "Failed to delete \"{$name}\".");
    }

    public function search(SearchFileRequest $request)
    {
        return redirect()->route('admin.documents.index', [
            'preset_id' => $request->getPresetId(),
            'search'    => $request->getQuery(),
        ]);
    }

    public function reprocess(ReprocessFileRequest $request, int $fileId)
    {
        $preset = $this->presetRegistry->getPreset($request->getPresetId());
        $file   = $this->fileQueryService->findForPreset($fileId, $preset->id);

        if (!$file) {
            return back()->with('error', "File #{$fileId} not found.");
        }

        try {
            $this->fileService->process($file, $preset);
            $file->refresh();
            return back()->with(
                'success',
                "File \"{$file->original_name}\" reprocessed ({$file->chunk_count} chunks)."
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Reprocessing failed: ' . $e->getMessage());
        }
    }

    /**
     * Download a file by ID.
     * Works for both Laravel storage and sandbox driver files.
     */
    public function download(DownloadFileRequest $request, int $fileId)
    {
        $presetId = $request->getPresetId();
        $file     = $presetId
            ? $this->fileQueryService->findForPreset($fileId, $presetId)
            : \App\Models\File::find($fileId);

        if (!$file) {
            abort(404, 'File not found.');
        }

        try {
            $absPath = $this->fileService->getDownloadPath($file);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->download($absPath, $file->original_name, [
            'Content-Type' => $file->mime_type,
        ]);
    }

}
