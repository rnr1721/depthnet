<?php

namespace App\Services\Agent\Memory;

use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Models\AiPreset;
use App\Models\MemoryItem;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Service for managing AI preset memory items
 * Handles CRUD operations and memory limit enforcement
 */
class MemoryService implements MemoryServiceInterface
{
    private bool $isProcessingOverflow = false;

    public function __construct(
        protected LoggerInterface $logger,
        protected MemoryItem $memoryItemModel
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getMemoryItems(AiPreset $preset): Collection
    {
        return $this->memoryItemModel->forPreset($preset->id)
            ->ordered()
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function getFormattedMemory(AiPreset $preset): string
    {
        $items = $this->getMemoryItems($preset);

        if ($items->isEmpty()) {
            return '';
        }

        $formatted = [];
        foreach ($items as $index => $item) {
            $number = $index + 1;
            $formatted[] = "{$number}. {$item->content}";
        }

        return implode("\n", $formatted);
    }

    /**
     * @inheritDoc
     */
    public function addMemoryItem(AiPreset $preset, string $content, array $config = []): array
    {
        try {
            $content = trim($content);
            if (empty($content)) {
                return [
                    'success' => false,
                    'message' => 'Error: Memory content cannot be empty.'
                ];
            }

            // Get current items count for position
            $currentCount = $this->memoryItemModel->forPreset($preset->id)->count();
            $newPosition = $currentCount + 1;

            // Check memory limit before adding (skip if flag is set)
            $skipLimitCheck = $config['skip_limit_check'] ?? false;
            if (!$skipLimitCheck) {
                $limitResult = $this->checkMemoryLimitWithNewItem($preset, $content, $config);
                if (!$limitResult['fits']) {
                    return $this->handleMemoryOverflow($preset, $content, 'append', $config);
                }
            }

            // Create new memory item
            $memoryItem = $this->memoryItemModel->create([
                'preset_id' => $preset->id,
                'content' => $content,
                'position' => $newPosition
            ]);

            return [
                'success' => true,
                'message' => "Memory item #{$newPosition} added successfully.",
                'item' => $memoryItem
            ];

        } catch (\Throwable $e) {
            $this->logger->error("MemoryService::addMemoryItem error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error adding to memory: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function replaceMemory(AiPreset $preset, string $content, array $config = []): array
    {
        try {
            $content = trim($content);

            // Check memory limit (skip if flag is set)
            $skipLimitCheck = $config['skip_limit_check'] ?? false;
            if (!$skipLimitCheck && !$this->checkMemoryLimit($content, $config)) {
                return $this->handleMemoryOverflow($preset, $content, 'replace', $config);
            }

            // Save version if enabled
            if ($config['enable_versioning'] ?? false) {
                $this->saveVersion($preset, $config);
            }

            // Clear existing items and add new one
            $this->memoryItemModel->forPreset($preset->id)->delete();

            if (!empty($content)) {
                $this->memoryItemModel->create([
                    'preset_id' => $preset->id,
                    'content' => $content,
                    'position' => 1
                ]);
            }

            return [
                'success' => true,
                'message' => 'Memory replaced successfully. New content stored.'
            ];

        } catch (\Throwable $e) {
            $this->logger->error("MemoryService::replaceMemory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error replacing memory: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteMemoryItem(AiPreset $preset, int $itemNumber, array $config = []): array
    {
        try {
            if ($itemNumber < 1) {
                return [
                    'success' => false,
                    'message' => 'Error: Invalid item number. Must be a positive integer.'
                ];
            }

            $items = $this->getMemoryItems($preset);

            if ($itemNumber > $items->count()) {
                return [
                    'success' => false,
                    'message' => "Error: Item #{$itemNumber} does not exist. Memory has {$items->count()} items."
                ];
            }

            // Save version if enabled
            if ($config['enable_versioning'] ?? false) {
                $this->saveVersion($preset, $config);
            }

            // Get item to delete (convert to 0-indexed)
            $itemToDelete = $items[$itemNumber - 1];
            $itemToDelete->delete();

            // Reorder remaining items
            $this->reorderItems($preset);

            $remainingCount = $items->count() - 1;
            $message = $remainingCount === 0
                ? "Memory item #{$itemNumber} deleted. Memory is now empty."
                : "Memory item #{$itemNumber} deleted successfully.";

            return [
                'success' => true,
                'message' => $message
            ];

        } catch (\Throwable $e) {
            $this->logger->error("MemoryService::deleteMemoryItem error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error deleting memory item: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function clearMemory(AiPreset $preset, array $config = []): array
    {
        try {
            if ($config['enable_versioning'] ?? false) {
                $this->saveVersion($preset, $config);
            }

            $this->memoryItemModel->forPreset($preset->id)->delete();

            return [
                'success' => true,
                'message' => 'Memory cleared successfully.'
            ];

        } catch (\Throwable $e) {
            $this->logger->error("MemoryService::clearMemory error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error clearing memory: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getMemoryStats(AiPreset $preset, array $config = []): array
    {
        $items = $this->getMemoryItems($preset);
        $totalLength = $items->sum('content_length');
        $limit = $this->getMemoryLimit($config);

        return [
            'total_items' => $items->count(),
            'total_length' => $totalLength,
            'limit' => $limit,
            'usage_percentage' => $limit > 0 ? round(($totalLength / $limit) * 100, 2) : 0,
            'is_near_limit' => $totalLength > ($limit * 0.8), // 80% threshold
            'is_over_limit' => $totalLength > $limit
        ];
    }

    /**
     * @inheritDoc
     */
    public function searchMemory(AiPreset $preset, string $query): Collection
    {
        return $this->memoryItemModel->forPreset($preset->id)
            ->where('content', 'LIKE', '%' . $query . '%')
            ->ordered()
            ->get();
    }

    /**
     * @inheritDoc
     */
    public function getMemoryForContext(AiPreset $preset, ?int $maxLength = null): string
    {
        $formatted = $this->getFormattedMemory($preset);

        if ($maxLength && strlen($formatted) > $maxLength) {
            // Truncate but try to keep complete items
            $items = $this->getMemoryItems($preset);
            $truncated = [];
            $currentLength = 0;

            foreach ($items as $index => $item) {
                $number = $index + 1;
                $line = "{$number}. {$item->content}\n";

                if ($currentLength + strlen($line) > $maxLength) {
                    break;
                }

                $truncated[] = $line;
                $currentLength += strlen($line);
            }

            return rtrim(implode('', $truncated));
        }

        return $formatted;
    }

    /**
     * Check if content fits within memory limit
     *
     * @param string $content
     * @param array $config
     * @return boolean
     */
    protected function checkMemoryLimit(string $content, array $config): bool
    {
        return strlen($content) <= $this->getMemoryLimit($config);
    }

    /**
     * Check if adding new item would exceed memory limit
     *
     * @param AiPreset $preset
     * @param string $newContent
     * @param array $config
     * @return array
     */
    protected function checkMemoryLimitWithNewItem(AiPreset $preset, string $newContent, array $config): array
    {
        $currentMemory = $this->getFormattedMemory($preset);
        $items = $this->getMemoryItems($preset);

        // Simulate adding new item
        $newPosition = $items->count() + 1;
        $newLine = "{$newPosition}. {$newContent}";
        $totalContent = empty($currentMemory) ? $newLine : $currentMemory . "\n" . $newLine;

        $limit = $this->getMemoryLimit($config);

        return [
            'fits' => strlen($totalContent) <= $limit,
            'current_length' => strlen($currentMemory),
            'new_length' => strlen($totalContent),
            'limit' => $limit
        ];
    }

    /**
     * Handle memory overflow based on strategy
     *
     * @param AiPreset $preset
     * @param string $newContent
     * @param string $operation
     * @param array $config
     * @return array
     */
    protected function handleMemoryOverflow(AiPreset $preset, string $newContent, string $operation, array $config): array
    {
        // Prevent infinite recursion
        if ($this->isProcessingOverflow) {
            $this->logger->warning('Memory overflow processing already in progress, skipping');
            return [
                'success' => false,
                'message' => 'Memory overflow processing already in progress'
            ];
        }

        $strategy = $this->getCleanupStrategy($config);
        $limit = $this->getMemoryLimit($config);

        if (!$this->isAutoCleanupEnabled($config)) {
            return [
                'success' => false,
                'message' => "Error: Memory limit ({$limit} chars) exceeded. Auto cleanup is disabled."
            ];
        }

        $this->isProcessingOverflow = true;

        try {
            switch ($strategy) {
                case 'truncate_old':
                    return $this->handleTruncateOld($preset, $newContent, $operation, $config);

                case 'truncate_new':
                    return $this->handleTruncateNew($preset, $newContent, $operation, $config);

                case 'reject':
                    return [
                        'success' => false,
                        'message' => "Error: Memory limit ({$limit} chars) exceeded. New content rejected."
                    ];

                case 'compress':
                    return $this->handleCompress($preset, $newContent, $operation, $config);

                default:
                    // Fallback to truncate_old strategy for unknown strategies
                    return $this->handleTruncateOld($preset, $newContent, $operation, $config);
            }

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Error handling memory overflow: " . $e->getMessage()
            ];
        } finally {
            $this->isProcessingOverflow = false;
        }
    }

    /**
     * Handle truncate_old strategy
     *
     * @param AiPreset $preset
     * @param string $newContent
     * @param string $operation
     * @param array $config
     * @return array
     */
    protected function handleTruncateOld(AiPreset $preset, string $newContent, string $operation, array $config): array
    {
        $limit = $this->getMemoryLimit($config);

        if ($operation === 'append') {
            // First add the new item directly without limit checks
            $currentCount = $this->memoryItemModel->forPreset($preset->id)->count();
            $newPosition = $currentCount + 1;

            $this->memoryItemModel->create([
                'preset_id' => $preset->id,
                'content' => $newContent,
                'position' => $newPosition
            ]);

            // Now remove oldest items until we fit within the limit
            $maxIterations = 100; // Safety valve
            $iterations = 0;

            while ($iterations < $maxIterations) {
                $formatted = $this->getFormattedMemory($preset);
                if (strlen($formatted) <= $limit) {
                    break;
                }

                $oldestItem = $this->memoryItemModel->forPreset($preset->id)->ordered()->first();
                if (!$oldestItem) {
                    break;
                }

                $oldestItem->delete();
                $iterations++;
            }

            if ($iterations >= $maxIterations) {
                $this->logger->warning('Memory cleanup hit maximum iterations limit');
            }

            $this->reorderItems($preset);

        } else { // replace
            $this->memoryItemModel->forPreset($preset->id)->delete();
            $truncatedContent = substr($newContent, 0, $limit);
            $this->memoryItemModel->create([
                'preset_id' => $preset->id,
                'content' => $truncatedContent,
                'position' => 1
            ]);
        }

        return [
            'success' => true,
            'message' => "Memory updated with overflow handling (truncate_old). Content may have been modified to fit limit."
        ];
    }

    /**
     * Handle truncate_new strategy
     *
     * @param AiPreset $preset
     * @param string $newContent
     * @param string $operation
     * @param array $config
     * @return array
     */
    protected function handleTruncateNew(AiPreset $preset, string $newContent, string $operation, array $config): array
    {
        $limit = $this->getMemoryLimit($config);

        if ($operation === 'append') {
            $currentLength = strlen($this->getFormattedMemory($preset));
            $availableSpace = $limit - $currentLength - 10; // Leave space for formatting

            if ($availableSpace > 0) {
                $truncatedContent = substr($newContent, 0, $availableSpace);

                $currentCount = $this->memoryItemModel->forPreset($preset->id)->count();
                $newPosition = $currentCount + 1;

                $this->memoryItemModel->create([
                    'preset_id' => $preset->id,
                    'content' => $truncatedContent,
                    'position' => $newPosition
                ]);

                return [
                    'success' => true,
                    'message' => "Memory item #{$newPosition} added successfully (content truncated)."
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'No space available for new content.'
                ];
            }
        } else { // replace
            $this->memoryItemModel->forPreset($preset->id)->delete();
            $truncatedContent = substr($newContent, 0, $limit);
            $this->memoryItemModel->create([
                'preset_id' => $preset->id,
                'content' => $truncatedContent,
                'position' => 1
            ]);
        }

        return [
            'success' => true,
            'message' => "Memory updated with overflow handling (truncate_new). Content may have been modified to fit limit."
        ];
    }

    /**
     * Handle compress strategy
     *
     * @param AiPreset $preset
     * @param string $newContent
     * @param string $operation
     * @param array $config
     * @return array
     */
    protected function handleCompress(AiPreset $preset, string $newContent, string $operation, array $config): array
    {
        $limit = $this->getMemoryLimit($config);

        // Add/replace content first
        if ($operation === 'append') {
            $currentCount = $this->memoryItemModel->forPreset($preset->id)->count();
            $newPosition = $currentCount + 1;

            $this->memoryItemModel->create([
                'preset_id' => $preset->id,
                'content' => $newContent,
                'position' => $newPosition
            ]);
        } else {
            $this->memoryItemModel->forPreset($preset->id)->delete();
            $this->memoryItemModel->create([
                'preset_id' => $preset->id,
                'content' => $newContent,
                'position' => 1
            ]);
        }

        // Compress all items by removing extra whitespace
        $items = $this->getMemoryItems($preset);
        foreach ($items as $item) {
            $compressed = preg_replace('/\s+/', ' ', trim($item->content));
            $item->update(['content' => $compressed]);
        }

        // If still too long, start removing oldest items
        $maxIterations = 100; // Safety valve
        $iterations = 0;

        while ($iterations < $maxIterations) {
            $formatted = $this->getFormattedMemory($preset);
            if (strlen($formatted) <= $limit) {
                break;
            }

            $oldestItem = $this->memoryItemModel->forPreset($preset->id)->ordered()->first();
            if (!$oldestItem) {
                break;
            }

            $oldestItem->delete();
            $iterations++;
        }

        if ($iterations >= $maxIterations) {
            $this->logger->warning('Memory compression cleanup hit maximum iterations limit');
        }

        $this->reorderItems($preset);

        return [
            'success' => true,
            'message' => "Memory updated with overflow handling (compress). Content may have been modified to fit limit."
        ];
    }

    /**
     * Reorder items to have sequential positions starting from 1
     *
     * @param AiPreset $preset
     * @return void
     */
    protected function reorderItems(AiPreset $preset): void
    {
        $items = $this->memoryItemModel->forPreset($preset->id)->ordered()->get();

        foreach ($items as $index => $item) {
            $newPosition = $index + 1;
            if ($item->position !== $newPosition) {
                $item->update(['position' => $newPosition]);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getMemoryLimit(array $config): int
    {
        return $config['memory_limit'] ?? 2000;
    }

    /**
     * @inheritDoc
     */
    public function isAutoCleanupEnabled(array $config): bool
    {
        return $config['auto_cleanup'] ?? true;
    }

    /**
     * @inheritDoc
     */
    public function getCleanupStrategy(array $config): string
    {
        return $config['cleanup_strategy'] ?? 'truncate_old';
    }

    /**
     * @inheritDoc
     */
    public function isVersioningEnabled(array $config): bool
    {
        return $config['enable_versioning'] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function getMaxVersions(array $config): int
    {
        return $config['max_versions'] ?? 3;
    }

    /**
     * Save current memory state as version (placeholder for future implementation)
     *
     * @param AiPreset $preset
     * @param array $config
     * @return void
     */
    protected function saveVersion(AiPreset $preset, array $config): void
    {
        // TODO: Implement versioning logic
        // This could create a backup in a memory_versions table
        // Use $this->getMaxVersions($config) to limit versions
    }
}
