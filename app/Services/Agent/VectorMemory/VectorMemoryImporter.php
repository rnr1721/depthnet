<?php

namespace App\Services\Agent\VectorMemory;

use App\Contracts\Agent\VectorMemory\VectorMemoryImporterInterface;
use App\Models\AiPreset;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * Service for importing vector memories from various content types
 */
class VectorMemoryImporter implements VectorMemoryImporterInterface
{
    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDocs
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
            // Validate content is not empty
            if (empty(trim($content))) {
                return [
                    'success' => false,
                    'message' => 'Content is empty or contains no valid data.'
                ];
            }

            if ($isJson) {
                return $this->importFromJson($preset, $content, $config, $storeMemoryCallback);
            } else {
                return $this->importFromText($preset, $content, $config, $storeMemoryCallback);
            }

        } catch (\Throwable $e) {
            $this->logger->error("VectorMemoryImporter::importFromContent error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Import failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Import from JSON format
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config
     * @param Closure $storeMemoryCallback
     * @return array
     */
    protected function importFromJson(AiPreset $preset, string $content, array $config, Closure $storeMemoryCallback): array
    {
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

        $successCount = 0;
        $errorCount = 0;

        foreach ($data['memories'] as $memoryData) {
            if (isset($memoryData['content'])) {
                $result = $storeMemoryCallback($preset, $memoryData['content'], $config);

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }
        }

        return [
            'success' => true,
            'success_count' => $successCount,
            'error_count' => $errorCount
        ];
    }

    /**
     * Import from plain text format
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config
     * @param Closure $storeMemoryCallback
     * @return array
     */
    protected function importFromText(AiPreset $preset, string $content, array $config, Closure $storeMemoryCallback): array
    {
        // Treat as plain text - split by lines and add each as separate memory
        $lines = array_filter(array_map('trim', explode("\n", $content)));

        $successCount = 0;
        $errorCount = 0;

        foreach ($lines as $line) {
            if (!empty($line)) {
                $result = $storeMemoryCallback($preset, $line, $config);

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }
        }

        return [
            'success' => true,
            'success_count' => $successCount,
            'error_count' => $errorCount
        ];
    }
}
