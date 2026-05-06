<?php

namespace App\Contracts\Agent\FileStorage;

use App\Models\AiPreset;
use App\Models\File;
use Illuminate\Http\UploadedFile;

interface FileServiceInterface
{
    /**
     * Store an uploaded file for a preset, run the processing pipeline,
     * and return the persisted File model.
     *
     * @param  UploadedFile  $upload
     * @param  AiPreset      $preset
     * @param  string        $driver       laravel|sandbox
     * @param  string        $scope        private|global
     * @param  string|null   $projectSlug  Optional project context
     */
    public function store(
        UploadedFile $upload,
        AiPreset $preset,
        string $driver = 'laravel',
        string $scope = 'private',
        ?string $projectSlug = null,
    ): File;

    /**
     * Run the processing pipeline for an already-stored file.
     * Safe to call multiple times — re-indexes from scratch.
     *
     * @param File $file
     * @param AiPreset $preset
     * @return void
     */
    public function process(File $file, AiPreset $preset): void;

    /**
     * Search file chunks visible to a preset using embedding → TF-IDF fallback.
     *
     * @param AiPreset $preset
     * @param string $query
     * @param integer $limit
     * @param float $threshold
     * @return array{success: bool, results: array, message: string}
     */
    public function search(
        AiPreset $preset,
        string $query,
        int $limit = 5,
        float $threshold = 0.2,
    ): array;

    /**
     * Delete a file — removes physical file, chunks, and the DB record.
     *
     * @param File $file
     * @return boolean
     */
    public function delete(File $file): bool;

    /**
     * Attach embeddings to chunks that were indexed without one.
     * Run via artisan command or queued job.
     *
     * @param AiPreset $preset
     * @param integer $batchSize
     * @return array{processed: int, failed: int, remaining: int}
     */
    public function backfillEmbeddings(AiPreset $preset, int $batchSize = 50): array;

    public function getDownloadPath(File $file): string;
}
