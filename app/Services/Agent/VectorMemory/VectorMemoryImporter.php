<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\VectorMemory\VectorMemoryImporterInterface;
use App\Models\AiPreset;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * Service for importing vector memories from various content types.
 *
 * Supports three export format versions:
 *   v1 — legacy format (no access_count, last_accessed_at, updated_at, domain)
 *   v2 — full format with associative memory fields (no domain)
 *   v3 — adds per-record `domain` field
 *
 * Missing fields fall back to safe defaults:
 *   - missing access_count/last_accessed_at → 0 / null
 *   - missing domain → null (storeWithMeta uses default_domain or 'global')
 *   - missing created_at/updated_at → current timestamp
 *
 * The importer is forward-compatible: it reads any known version transparently
 * and ignores unknown fields, so newer exports loaded into older versions of
 * the system still work for the fields they share.
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
     * Extracts metadata (dates, access stats, domain) from each memory entry
     * and passes it to the store callback so original values can be preserved.
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
     * Domain is taken from $config['default_domain'] (or 'global') unless
     * the caller passes 'force_domain' in $config.
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
                // Plain text imports get no per-record metadata — store callback uses defaults
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
     * For older exports (v1, v2): missing fields return null/defaults so the
     * store method knows to use sensible substitutes (current timestamp,
     * default domain, etc) instead of trying to restore non-existent data.
     *
     * @param array $memoryData   Single memory entry from export JSON
     * @param int   $exportVersion Export format version
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

            // Restore original creation date if present (v1 has created_at, v2+ has both)
            'created_at'       => $memoryData['created_at'] ?? null,
            'updated_at'       => $memoryData['updated_at'] ?? null,

            // v3+ field — null means "use default_domain (or 'global') in store method".
            // storeWithMeta also respects $config['force_domain'] which overrides this
            // regardless of source version — used by the admin "Target domain" feature.
            'domain'           => $memoryData['domain'] ?? null,
        ];
    }
}
