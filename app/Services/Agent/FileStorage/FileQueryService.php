<?php

namespace App\Services\Agent\FileStorage;

use App\Contracts\Agent\FileStorage\FileQueryServiceInterface;
use App\Models\File;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Encapsulates all File model queries.
 * Controllers and other services never touch the File model directly.
 */
class FileQueryService implements FileQueryServiceInterface
{
    public function __construct(
        protected File $fileModel
    ) {
    }

    /**
     * Paginated file list visible to a preset (private + global).
     */
    public function paginateForPreset(int $presetId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->fileModel->query()
            ->forPreset($presetId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * All processed files visible to a preset — used by RAG pipeline.
     */
    public function processedForPreset(int $presetId): Collection
    {
        return $this->fileModel->query()
            ->forPreset($presetId)
            ->processed()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Find a single file visible to a preset, or null.
     */
    public function findForPreset(int $fileId, int $presetId): ?File
    {
        return $this->fileModel->query()
            ->forPreset($presetId)
            ->find($fileId);
    }

    /**
     * Aggregate stats for the Document Manager header.
     *
     * @return array{total: int, processed: int, failed: int, pending: int, in_laravel: int, in_sandbox: int, total_size: int}
     */
    public function statsForPreset(int $presetId): array
    {
        $base = fn () => $this->fileModel->query()->forPreset($presetId);

        return [
            'total'      => (clone $base())->count(),
            'processed'  => (clone $base())->processed()->count(),
            'failed'     => (clone $base())->where('processing_status', 'failed')->count(),
            'pending'    => (clone $base())->where('processing_status', 'pending')->count(),
            'in_laravel' => (clone $base())->inLaravel()->count(),
            'in_sandbox' => (clone $base())->inSandbox()->count(),
            'total_size' => (clone $base())->sum('size'),
        ];
    }

    /**
     * Format a File model into the array shape expected by the Vue page.
     */
    public function format(File $file): array
    {
        return [
            'id'                => $file->id,
            'original_name'     => $file->original_name,
            'mime_type'         => $file->mime_type,
            'extension'         => $file->extension,
            'storage_driver'    => $file->storage_driver,
            'scope'             => $file->scope,
            'size'              => $file->size,
            'human_size'        => $file->human_size,
            'processing_status' => $file->processing_status,
            'chunk_count'       => $file->chunk_count,
            'meta'              => $file->meta ?? [],
            'created_at'        => $file->created_at,
        ];
    }

    /**
     * Format a collection of files.
     *
     * @return array[]
     */
    public function formatMany(iterable $files): array
    {
        $result = [];
        foreach ($files as $file) {
            $result[] = $this->format($file);
        }
        return $result;
    }
}
