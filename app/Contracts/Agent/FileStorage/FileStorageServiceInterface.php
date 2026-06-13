<?php

namespace App\Contracts\Agent\FileStorage;

use App\Models\AiPreset;
use App\Models\File;
use Illuminate\Http\UploadedFile;

/**
 * Contract for file storage drivers.
 *
 * Each driver knows how to physically store, retrieve and delete files
 * within its own root. Paths returned are always relative to the driver root
 * so they can be stored in files.storage_path and later resolved by the
 * same driver regardless of server environment.
 *
 * Drivers:
 *   - LaravelFileStorageService  → Laravel storage disk (read-only for agent)
 *   - SandboxFileStorageService  → Preset sandbox /shared/{name}/... (full agent access)
 */
interface FileStorageServiceInterface
{
    /**
     * Driver identifier — matches files.storage_driver enum value.
     *
     * @return string
     */
    public function getDriver(): string;

    /**
     * Store an uploaded file for a preset and return the relative storage path.
     *
     * @param  UploadedFile  $upload
     * @param  AiPreset      $preset
     * @param  string|null   $projectSlug  If set, file goes into project directory
     * @return string                       Relative path stored in files.storage_path
     */
    public function store(UploadedFile $upload, AiPreset $preset, ?string $projectSlug = null): string;

    /**
     * Return the absolute filesystem path for a stored file.
     * Used by processors and the agent to physically read the file.
     *
     * @param File $file
     * @return string
     */
    public function absolutePath(File $file): string;

    /**
     * Return the absolute filesystem path for a preset's workspace root.
     * Useful for the agent to browse or organise files via TerminalPlugin.
     *
     * @param AiPreset $preset
     * @param string|null $projectSlug
     * @return string
     */
    public function workspacePath(AiPreset $preset, ?string $projectSlug = null): string;

    /**
     * Delete the physical file from storage.
     * Called when the File model is deleted.
     *
     * @param File $file
     * @return boolean
     */
    public function delete(File $file): bool;

    /**
     * Check whether the physical file actually exists on disk.
     *
     * @param File $file
     * @return boolean
     */
    public function exists(File $file): bool;
}
