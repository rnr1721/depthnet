<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\VectorMemory\VectorMemoryImporterInterface;
use App\Models\AiPreset;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * Service for importing vector memories from various content types.
 *
 * Supports two export format versions:
 *   v1 — legacy format (no access_count, last_accessed_at, updated_at)
 *   v2 — full format with all associative memory fields
 *
 * Both formats are handled transparently — missing fields fall back to safe defaults.
 */
class VectorMemoryImporter implements VectorMemoryImporterInterface
{
    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function importFromContent(
        AiPreset $preset,
        string $content,
        bool $isJson,
        bool $replaceExisting,
        array $config,
        Closure $storeMemoryCallback
    ): array {
        try {
            if (empty(trim($content))) {
                return [
                    'success' => false,
                    'message' => 'Content is empty or contains no valid data.'
                ];
            }

            if ($isJson) {
                return $this->importFromJson($preset, $content, $config, $storeMemoryCallback);
            }

            return $this->importFromText($preset, $content, $config, $storeMemoryCallback);

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryImporter::importFromContent error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Import failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Import from JSON format.
     *
     * Extracts metadata (dates, access stats) from each memory entry and passes
     * it to the store callback so original timestamps can be preserved.
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config
     * @param Closure $storeMemoryCallback
     * @return array
     */
    protected function importFromJson(
        AiPreset $preset,
        string $content,
        array $config,
        Closure $storeMemoryCallback
    ): array {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JSON format.'
            ];
        }

        if (!isset($data['memories']) || !is_array($data['memories'])) {
            return [
                'success' => false,
                'message' => 'Invalid JSON structure. Expected memories array.'
            ];
        }

        $exportVersion = $data['export_version'] ?? 1;
        $successCount  = 0;
        $errorCount    = 0;

        foreach ($data['memories'] as $memoryData) {
            if (empty($memoryData['content'])) {
                $errorCount++;
                continue;
            }

            // Extract metadata — gracefully handle v1 exports without these fields
            $meta = $this->extractMeta($memoryData, $exportVersion);

            $result = $storeMemoryCallback($preset, $memoryData['content'], $meta, $config);

            if ($result['success']) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return [
            'success'        => true,
            'export_version' => $exportVersion,
            'success_count'  => $successCount,
            'error_count'    => $errorCount,
        ];
    }

    /**
     * Import from plain text format.
     * Each non-empty line becomes a separate memory with default metadata.
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config
     * @param Closure $storeMemoryCallback
     * @return array
     */
    protected function importFromText(
        AiPreset $preset,
        string $content,
        array $config,
        Closure $storeMemoryCallback
    ): array {
        $lines = array_filter(array_map('trim', explode("\n", $content)));

        $successCount = 0;
        $errorCount   = 0;

        foreach ($lines as $line) {
            if (!empty($line)) {
                // Plain text imports get no metadata — store callback uses defaults
                $result = $storeMemoryCallback($preset, $line, [], $config);

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }
        }

        return [
            'success'       => true,
            'success_count' => $successCount,
            'error_count'   => $errorCount,
        ];
    }

    /**
     * Extract and normalize metadata from a memory entry.
     *
     * For v1 exports: missing fields return null/defaults so the store method
     * knows to use current timestamp instead of trying to restore a non-existent one.
     *
     * @param array $memoryData Single memory entry from export JSON
     * @param int $exportVersion Export format version
     * @return array Normalized metadata array
     */
    protected function extractMeta(array $memoryData, int $exportVersion): array
    {
        return [
            'importance'       => isset($memoryData['importance'])
                ? (float) $memoryData['importance']
                : 1.0,

            // v1 exports don't have these — null means "use defaults in store method"
            'access_count'     => isset($memoryData['access_count'])
                ? (int) $memoryData['access_count']
                : 0,

            'last_accessed_at' => $memoryData['last_accessed_at'] ?? null,

            // Restore original creation date if present (v1 has created_at, v2 has both)
            'created_at'       => $memoryData['created_at'] ?? null,
            'updated_at'       => $memoryData['updated_at'] ?? null,
        ];
    }
}
