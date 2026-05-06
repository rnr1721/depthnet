<?php

namespace App\Services\Agent\FileStorage;

use App\Contracts\Agent\FileStorage\FileStorageServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Models\AiPreset;
use App\Models\File;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;

/**
 * Sandbox storage driver.
 *
 * Files live inside the preset's assigned sandbox home directory,
 * which is bind-mounted into Laravel at /shared/{sandbox_name}/:
 *
 *   Without project: /shared/{sandbox_name}/workspace/presets/{preset_id}/files/{filename}
 *   With project:    /shared/{sandbox_name}/projects/{project_slug}/files/{filename}
 *
 * The agent has full read/write access via TerminalPlugin — it can read,
 * modify, create and delete files as sandbox-user.
 * Suitable for autonomous agent workspaces and project outputs.
 */
class SandboxFileStorageService implements FileStorageServiceInterface
{
    public const DRIVER = 'sandbox';

    public function __construct(
        protected PresetSandboxServiceInterface $sandboxService,
        protected LoggerInterface $logger,
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
        $sandboxName = $this->resolveSandboxName($preset);
        $directory   = $this->absoluteDirectory($sandboxName, $preset, $projectSlug);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename    = $this->uniqueFilename($upload);
        $destination = "{$directory}/{$filename}";

        if (!$upload->move($directory, $filename)) {
            throw new \RuntimeException(
                "SandboxFileStorageService: failed to move file '{$filename}' to '{$directory}'"
            );
        }

        // Store path relative to /shared/{sandbox_name}/ so it's portable
        $relativePath = $this->relativeDirectory($preset, $projectSlug) . "/{$filename}";

        $this->logger->info('SandboxFileStorageService: file stored', [
            'preset_id'    => $preset->id,
            'sandbox_name' => $sandboxName,
            'project_slug' => $projectSlug,
            'path'         => $relativePath,
        ]);

        return $relativePath;
    }

    /** @inheritDoc */
    public function absolutePath(File $file): string
    {
        $sandboxName = $this->resolveSandboxNameForFile($file);
        return $this->sharedRoot() . "/{$sandboxName}/{$file->storage_path}";
    }

    /** @inheritDoc */
    public function workspacePath(AiPreset $preset, ?string $projectSlug = null): string
    {
        $sandboxName = $this->resolveSandboxName($preset);
        return $this->absoluteDirectory($sandboxName, $preset, $projectSlug);
    }

    /** @inheritDoc */
    public function delete(File $file): bool
    {
        $path = $this->absolutePath($file);

        if (!file_exists($path)) {
            return true;
        }

        $deleted = unlink($path);

        if (!$deleted) {
            $this->logger->warning('SandboxFileStorageService: failed to delete file', [
                'file_id' => $file->id,
                'path'    => $path,
            ]);
        }

        return $deleted;
    }

    /** @inheritDoc */
    public function exists(File $file): bool
    {
        return file_exists($this->absolutePath($file));
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Resolve sandbox name for a preset via PresetSandboxService.
     *
     * @throws \RuntimeException if no sandbox is assigned
     */
    private function resolveSandboxName(AiPreset $preset): string
    {
        $assignment = $this->sandboxService->getAssignedSandbox($preset->id);

        if (!$assignment || empty($assignment['sandbox'])) {
            throw new \RuntimeException(
                "SandboxFileStorageService: no sandbox assigned to preset {$preset->id}"
            );
        }

        $sandboxId    = $assignment['sandbox']->id;
        $sharedPrefix = config('sandbox.sandbox.shared_prefix', 'sandbox');

        return "{$sharedPrefix}-{$sandboxId}"; // 'sandbox-qwen'
    }

    /**
     * Resolve sandbox name using the preset relation on the file.
     *
     * @throws \RuntimeException if preset or sandbox is unavailable
     */
    private function resolveSandboxNameForFile(File $file): string
    {
        if (!$file->preset) {
            throw new \RuntimeException(
                "SandboxFileStorageService: file {$file->id} has no preset — cannot resolve sandbox"
            );
        }

        return $this->resolveSandboxName($file->preset);
    }

    /**
     * Absolute directory on the host filesystem.
     */
    private function absoluteDirectory(string $sandboxName, AiPreset $preset, ?string $projectSlug): string
    {
        return $this->sharedRoot() . "/{$sandboxName}/" . $this->relativeDirectory($preset, $projectSlug);
    }

    /**
     * Relative directory inside the sandbox home, stored in files.storage_path prefix.
     *
     * Without project: workspace/presets/{preset_id}/files
     * With project:    projects/{project_slug}/files
     */
    private function relativeDirectory(AiPreset $preset, ?string $projectSlug): string
    {
        if ($projectSlug) {
            return "projects/{$projectSlug}/files";
        }

        return "files";
    }

    /**
     * Generate a unique filename preserving the original extension.
     */
    private function uniqueFilename(UploadedFile $upload): string
    {
        return $upload->getClientOriginalName();
    }

    /**
     * Root where all sandbox home directories are bind-mounted into Laravel.
     *
     * @return string
     */
    private function sharedRoot(): string
    {
        return rtrim(config('sandbox.sandbox.shared_path', '/shared'), '/');
    }

}
