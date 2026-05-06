<?php

namespace App\Contracts\Agent\FileStorage;

use App\Models\File;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface FileQueryServiceInterface
{
    /**
     * Paginated file list visible to a preset (private + global).
     *
     * @param integer $presetId
     * @param integer $perPage
     * @return LengthAwarePaginator
     */
    public function paginateForPreset(int $presetId, int $perPage = 20): LengthAwarePaginator;

    /**
     * All processed files visible to a preset — used by RAG pipeline.
     *
     * @param integer $presetId
     * @return Collection
     */
    public function processedForPreset(int $presetId): Collection;

    /**
     * Find a single file visible to a preset, or null.
     *
     * @param integer $fileId
     * @param integer $presetId
     * @return File|null
     */
    public function findForPreset(int $fileId, int $presetId): ?File;

    /**
     * Aggregate stats for the Document Manager header.
     *
     * @param integer $presetId
     * @return array{total: int, processed: int, failed: int, pending: int, in_laravel: int, in_sandbox: int, total_size: int}
     */
    public function statsForPreset(int $presetId): array;

    /**
     * Format a File model into the array shape expected by the Vue page.
     *
     * @param File $file
     * @return array
     */
    public function format(File $file): array;

    /**
     * Format a collection of files.
     *
     * @param iterable $files
     * @return array[]
     */
    public function formatMany(iterable $files): array;
}
