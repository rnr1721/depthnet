<?php

namespace App\Services\Agent\FileStorage;

use App\Contracts\Agent\FileStorage\FileStorageServiceInterface;
use App\Models\AiPreset;
use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;

/**
 * Laravel disk storage driver.
 *
 * Files live inside Laravel's default storage disk:
 *   storage/app/presets/{preset_id}/files/{filename}
 *   storage/app/projects/{project_slug}/files/{filename}
 *
 * The agent can read these files via their absolute path but cannot
 * modify them — this driver is intentionally read-only for the agent.
 * Suitable for user-uploaded reference documents.
 */
class LaravelFileStorageService implements FileStorageServiceInterface
{
    public const DRIVER = 'laravel';

    public function __construct(
        protected LoggerInterface $logger,
        protected string $disk = 'local',
    ) {
    }

    /** @inheritDoc */
    public function getDriver(): string
    {
        return self::DRIVER;
    }

    /** @inheritDoc */
    public function store(UploadedFile $upload, AiPreset $preset, ?string $projectSlug = null): string
    {
        $directory = $this->relativeDirectory($preset, $projectSlug);
        $filename  = $this->uniqueFilename($upload);

        $path = Storage::disk($this->disk)->putFileAs($directory, $upload, $filename);

        if ($path === false) {
            throw new \RuntimeException(
                "LaravelFileStorageService: failed to store file '{$filename}' for preset {$preset->id}"
            );
        }

        $this->logger->info('LaravelFileStorageService: file stored', [
            'preset_id'    => $preset->id,
            'project_slug' => $projectSlug,
            'path'         => $path,
        ]);

        return $path;
    }

    /** @inheritDoc */
    public function absolutePath(File $file): string
    {
        return Storage::disk($this->disk)->path($file->storage_path);
    }

    /** @inheritDoc */
    public function workspacePath(AiPreset $preset, ?string $projectSlug = null): string
    {
        return Storage::disk($this->disk)->path(
            $this->relativeDirectory($preset, $projectSlug)
        );
    }

    /** @inheritDoc */
    public function delete(File $file): bool
    {
        if (!Storage::disk($this->disk)->exists($file->storage_path)) {
            return true;
        }

        $deleted = Storage::disk($this->disk)->delete($file->storage_path);

        if (!$deleted) {
            $this->logger->warning('LaravelFileStorageService: failed to delete file', [
                'file_id' => $file->id,
                'path'    => $file->storage_path,
            ]);
        }

        return $deleted;
    }

    /** @inheritDoc */
    public function exists(File $file): bool
    {
        return Storage::disk($this->disk)->exists($file->storage_path);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Relative directory within the storage disk.
     *
     * Without project: presets/{preset_id}/files
     * With project:    projects/{project_slug}/files
     */
    private function relativeDirectory(AiPreset $preset, ?string $projectSlug): string
    {
        if ($projectSlug) {
            return "projects/{$projectSlug}/files";
        }

        return "presets/{$preset->id}/files";
    }

    /**
     * Generate a unique filename preserving the original extension.
     */
    private function uniqueFilename(UploadedFile $upload): string
    {
        $ext = $upload->getClientOriginalExtension();
        return uniqid('file_', true) . ($ext ? ".{$ext}" : '');
    }
}
