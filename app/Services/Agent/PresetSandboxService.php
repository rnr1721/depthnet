<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\PresetMetadataServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Exceptions\PresetException;
use App\Exceptions\Sandbox\SandboxException;
use Psr\Log\LoggerInterface;

/**
 * Service for managing preset-sandbox relationships
 *
 * Handles linking presets to sandboxes via metadata with proper validation
 * and cleanup when sandboxes are destroyed.
 */
class PresetSandboxService implements PresetSandboxServiceInterface
{
    public function __construct(
        protected PresetServiceInterface $presetService,
        protected PresetMetadataServiceInterface $metadataService,
        protected SandboxManagerInterface $sandboxManager,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function assignSandbox(int $presetId, string $sandboxId): bool
    {
        $preset = $this->presetService->findById($presetId);
        if (!$preset) {
            throw new PresetException("Preset not found: {$presetId}");
        }

        // Verify sandbox exists
        if (!$this->sandboxManager->sandboxExists($sandboxId)) {
            throw new PresetException("Sandbox not found: {$sandboxId}");
        }

        // Get sandbox details for validation
        $sandbox = $this->sandboxManager->getSandbox($sandboxId);
        if (!$sandbox || $sandbox->status !== 'running') {
            throw new PresetException("Sandbox is not running: {$sandboxId}");
        }

        // Remove previous assignment if exists
        $this->unassignSandbox($presetId);

        // Set new assignment in metadata
        $this->metadataService->setPluginMetadata($preset, 'sandbox', 'current_sandbox_id', $sandboxId);
        $this->metadataService->setPluginMetadata($preset, 'sandbox', 'assigned_at', now()->toISOString());
        $this->metadataService->setPluginMetadata($preset, 'sandbox', 'sandbox_type', $sandbox->type);

        $this->logger->info('Sandbox assigned to preset', [
            'preset_id' => $presetId,
            'preset_name' => $preset->name,
            'sandbox_id' => $sandboxId,
            'sandbox_type' => $sandbox->type
        ]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function unassignSandbox(int $presetId): bool
    {
        $preset = $this->presetService->findById($presetId);
        if (!$preset) {
            throw new PresetException("Preset not found: {$presetId}");
        }

        $currentSandboxId = $this->metadataService->getPluginMetadata($preset, 'sandbox', 'current_sandbox_id');

        if (!$currentSandboxId) {
            return true; // Nothing to unassign
        }

        // Clear sandbox metadata
        $this->metadataService->set($preset, 'sandbox', []);

        $this->logger->info('Sandbox unassigned from preset', [
            'preset_id' => $presetId,
            'preset_name' => $preset->name,
            'sandbox_id' => $currentSandboxId
        ]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getAssignedSandbox(int $presetId): ?array
    {
        $preset = $this->presetService->findById($presetId);
        if (!$preset) {
            throw new PresetException("Preset not found: {$presetId}");
        }

        $sandboxId = $this->metadataService->getPluginMetadata($preset, 'sandbox', 'current_sandbox_id');

        if (!$sandboxId) {
            return null;
        }

        // Verify sandbox still exists
        try {
            $sandbox = $this->sandboxManager->getSandbox($sandboxId);

            if (!$sandbox) {
                // Sandbox was deleted, clean up metadata
                $this->unassignSandbox($presetId);
                return null;
            }

            return [
                'sandbox_id' => $sandboxId,
                'sandbox' => $sandbox,
                'assigned_at' => $this->metadataService->getPluginMetadata($preset, 'sandbox', 'assigned_at'),
                'sandbox_type' => $this->metadataService->getPluginMetadata($preset, 'sandbox', 'sandbox_type')
            ];

        } catch (SandboxException $e) {
            // Sandbox manager error, clean up metadata
            $this->unassignSandbox($presetId);
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function hasAssignedSandbox(int $presetId): bool
    {
        return $this->getAssignedSandbox($presetId) !== null;
    }

    /**
     * @inheritDoc
     */
    public function getPresetsForSandbox(string $sandboxId): array
    {
        $allPresets = $this->presetService->getAllPresets();
        $assignedPresets = [];

        foreach ($allPresets as $preset) {
            $currentSandboxId = $this->metadataService->getPluginMetadata($preset, 'sandbox', 'current_sandbox_id');

            if ($currentSandboxId === $sandboxId) {
                $assignedPresets[] = [
                    'preset' => $preset,
                    'assigned_at' => $this->metadataService->getPluginMetadata($preset, 'sandbox', 'assigned_at'),
                    'sandbox_type' => $this->metadataService->getPluginMetadata($preset, 'sandbox', 'sandbox_type')
                ];
            }
        }

        return $assignedPresets;
    }

    /**
     * @inheritDoc
     */
    public function cleanupSandboxAssignments(string $sandboxId): int
    {
        $assignedPresets = $this->getPresetsForSandbox($sandboxId);
        $cleanedCount = 0;

        foreach ($assignedPresets as $assignedPreset) {
            try {
                $this->unassignSandbox($assignedPreset['preset']->id);
                $cleanedCount++;
            } catch (PresetException $e) {
                $this->logger->warning('Failed to cleanup sandbox assignment', [
                    'preset_id' => $assignedPreset['preset']->id,
                    'sandbox_id' => $sandboxId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($cleanedCount > 0) {
            $this->logger->info('Cleaned up sandbox assignments', [
                'sandbox_id' => $sandboxId,
                'presets_cleaned' => $cleanedCount
            ]);
        }

        return $cleanedCount;
    }

    /**
     * @inheritDoc
     */
    public function validateAndCleanupAssignments(): array
    {
        $allPresets = $this->presetService->getAllPresets();
        $stats = [
            'checked' => 0,
            'orphaned' => 0,
            'cleaned' => 0,
            'errors' => 0
        ];

        foreach ($allPresets as $preset) {
            $sandboxId = $this->metadataService->getPluginMetadata($preset, 'sandbox', 'current_sandbox_id');

            if (!$sandboxId) {
                continue; // No assignment
            }

            $stats['checked']++;

            try {
                $sandbox = $this->sandboxManager->getSandbox($sandboxId);

                if (!$sandbox) {
                    $stats['orphaned']++;
                    $this->unassignSandbox($preset->id);
                    $stats['cleaned']++;
                }
            } catch (SandboxException $e) {
                $stats['orphaned']++;
                $stats['errors']++;

                $this->logger->warning('Error validating sandbox assignment', [
                    'preset_id' => $preset->id,
                    'sandbox_id' => $sandboxId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Sandbox assignment validation completed', $stats);

        return $stats;
    }

    /**
     * @inheritDoc
     */
    public function getAssignmentStats(): array
    {
        $allPresets = $this->presetService->getAllPresets();
        $stats = [
            'total_presets' => count($allPresets),
            'assigned_presets' => 0,
            'unassigned_presets' => 0,
            'sandbox_types' => []
        ];

        foreach ($allPresets as $preset) {
            $sandboxId = $this->metadataService->getPluginMetadata($preset, 'sandbox', 'current_sandbox_id');

            if ($sandboxId) {
                $stats['assigned_presets']++;

                $sandboxType = $this->metadataService->getPluginMetadata($preset, 'sandbox', 'sandbox_type', 'unknown');
                $stats['sandbox_types'][$sandboxType] = ($stats['sandbox_types'][$sandboxType] ?? 0) + 1;
            } else {
                $stats['unassigned_presets']++;
            }
        }

        return $stats;
    }

    /**
     * @inheritDoc
     */
    public function createAndAssignSandbox(int $presetId, ?string $sandboxType = null, ?string $sandboxName = null): array
    {
        $preset = $this->presetService->findById($presetId);
        if (!$preset) {
            throw new PresetException("Preset not found: {$presetId}");
        }

        $sandboxType = $sandboxType ?? 'ubuntu-full';
        $sandboxName = $sandboxName ?? "preset-{$preset->name}-" . uniqid();

        try {
            $sandbox = $this->sandboxManager->createSandbox($sandboxType, $sandboxName);

            $this->assignSandbox($presetId, $sandbox->id);

            $this->logger->info('Created and assigned new sandbox for preset', [
                'preset_id' => $presetId,
                'sandbox_id' => $sandbox->id,
                'sandbox_type' => $sandboxType
            ]);

            return [
                'sandbox_id' => $sandbox->id,
                'sandbox' => $sandbox,
                'created' => true
            ];

        } catch (SandboxException $e) {
            throw new PresetException("Failed to create sandbox for preset: {$e->getMessage()}", 0, $e);
        }
    }
}
