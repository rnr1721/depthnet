<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\VectorMemory\VectorMemoryExporterInterface;
use App\Models\AiPreset;
use App\Models\VectorMemory;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Service for exporting vector memories as JSON downloads.
 *
 * Format versions:
 *   v1 — initial format (created_at only, no access/importance metadata)
 *   v2 — added access_count, last_accessed_at, updated_at
 *   v3 — added per-record `domain` field
 *
 * The importer reads any version transparently. Newer exports are still
 * compatible with older imports — unknown fields are ignored.
 */
class VectorMemoryExporter implements VectorMemoryExporterInterface
{
    /**
     * Current export format version. Bump when adding new fields.
     */
    private const EXPORT_VERSION = 3;

    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function export(AiPreset $preset, Collection $memories): array
    {
        try {
            $exportData = [
                'preset' => [
                    'id'   => $preset->id,
                    'name' => $preset->name,
                ],
                'export_date'     => now()->toISOString(),
                'export_version'  => self::EXPORT_VERSION,
                'total_memories'  => $memories->count(),
                'memories'        => $memories->map(function ($memory) {
                    return [
                        'id'               => $memory->id,
                        'domain'           => $memory->domain ?? VectorMemory::DEFAULT_DOMAIN,
                        'content'          => $memory->content,
                        'keywords'         => $memory->keywords,
                        'importance'       => $memory->importance,
                        'access_count'     => $memory->access_count ?? 0,
                        'last_accessed_at' => $memory->last_accessed_at?->toISOString(),
                        'vector_size'      => count($memory->tfidf_vector ?? []),
                        'created_at'       => $memory->created_at->toISOString(),
                        'updated_at'       => $memory->updated_at->toISOString(),
                    ];
                })->toArray()
            ];

            $filename    = $this->generateFilename($preset);
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return [
                'success'  => true,
                'content'  => $jsonContent,
                'filename' => $filename,
                'headers'  => [
                    'Content-Type'        => 'application/json',
                    'Content-Disposition' => "attachment; filename=\"{$filename}\""
                ]
            ];

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryExporter::export error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Export failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Generate filename for export
     *
     * @param AiPreset $preset
     * @return string
     */
    protected function generateFilename(AiPreset $preset): string
    {
        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $preset->name);
        $timestamp     = date('Y-m-d_H-i-s');

        return "vector_memory_preset_{$preset->id}_{$sanitizedName}_{$timestamp}.json";
    }
}
