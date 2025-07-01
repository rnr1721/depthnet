<?php

namespace App\Services\Agent;

use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PresetMetadataServiceInterface;
use App\Models\AiPreset;

/**
 * Service for managing plugin metadata within presets
 *
 * Wrapper around PresetMetadataService that manages plugin metadata
 * in the 'plugins' namespace with plugin-specific operations.
 */
class PluginMetadataService implements PluginMetadataServiceInterface
{
    protected const PLUGINS_NAMESPACE = 'plugins';

    public function __construct(
        protected PresetMetadataServiceInterface $metadataService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(AiPreset $preset, string $pluginName, ?string $key = null, $default = null)
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->get($preset, $pluginPath, $default);
    }

    /**
     * @inheritDoc
     */
    public function set(AiPreset $preset, string $pluginName, string $key, $value): bool
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->set($preset, $pluginPath, $value);
    }

    /**
     * @inheritDoc
     */
    public function update(AiPreset $preset, string $pluginName, array $data): bool
    {
        $updateData = [];
        foreach ($data as $key => $value) {
            $pluginPath = $this->getPluginPath($pluginName, $key);
            $updateData[$pluginPath] = $value;
        }

        return $this->metadataService->update($preset, $updateData);
    }

    /**
     * @inheritDoc
     */
    public function remove(AiPreset $preset, string $pluginName, string $key): bool
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->remove($preset, $pluginPath);
    }

    /**
     * @inheritDoc
     */
    public function has(AiPreset $preset, string $pluginName, ?string $key = null): bool
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->has($preset, $pluginPath);
    }

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset, string $pluginName): bool
    {
        $pluginPath = self::PLUGINS_NAMESPACE . '.' . $pluginName;
        return $this->metadataService->remove($preset, $pluginPath);
    }

    /**
     * @inheritDoc
     */
    public function increment(AiPreset $preset, string $pluginName, string $key, int $amount = 1, ?int $max = null): bool
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->increment($preset, $pluginPath, $amount, $max);
    }

    /**
     * @inheritDoc
     */
    public function decrement(AiPreset $preset, string $pluginName, string $key, int $amount = 1, ?int $min = null): bool
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->decrement($preset, $pluginPath, $amount, $min);
    }

    /**
     * @inheritDoc
     */
    public function setNumeric(AiPreset $preset, string $pluginName, string $key, int $value, ?int $min = null, ?int $max = null): bool
    {
        $pluginPath = $this->getPluginPath($pluginName, $key);
        return $this->metadataService->setNumeric($preset, $pluginPath, $value, $min, $max);
    }

    /**
     * @inheritDoc
     */
    public function export(AiPreset $preset, string $pluginName): array
    {
        $pluginPath = self::PLUGINS_NAMESPACE . '.' . $pluginName;
        return $this->metadataService->get($preset, $pluginPath, []);
    }

    /**
     * @inheritDoc
     */
    public function import(AiPreset $preset, string $pluginName, array $metadata, bool $merge = true): bool
    {
        $pluginPath = self::PLUGINS_NAMESPACE . '.' . $pluginName;

        if ($merge) {
            $existingData = $this->metadataService->get($preset, $pluginPath, []);
            $metadata = array_merge($existingData, $metadata);
        }

        return $this->metadataService->set($preset, $pluginPath, $metadata);
    }

    /**
     * @inheritDoc
     */
    public function getPluginList(AiPreset $preset): array
    {
        $pluginsData = $this->metadataService->get($preset, self::PLUGINS_NAMESPACE, []);
        return array_keys($pluginsData);
    }

    /**
     * @inheritDoc
     */
    public function hasPlugin(AiPreset $preset, string $pluginName): bool
    {
        $pluginPath = self::PLUGINS_NAMESPACE . '.' . $pluginName;
        return $this->metadataService->has($preset, $pluginPath);
    }

    /**
     * @inheritDoc
     */
    public function getAllPluginMetadata(AiPreset $preset): array
    {
        return $this->metadataService->get($preset, self::PLUGINS_NAMESPACE, []);
    }

    /**
     * @inheritDoc
     */
    public function clearAll(AiPreset $preset): bool
    {
        return $this->metadataService->clear($preset, self::PLUGINS_NAMESPACE);
    }

    /**
     * @inheritDoc
     */
    public function search(AiPreset $preset, string $pluginName, $searchValue, bool $strict = false): array
    {
        $pluginData = $this->export($preset, $pluginName);
        $results = [];

        $this->searchRecursive($pluginData, $searchValue, $results, '', $strict);

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function copyPlugin(AiPreset $sourcePreset, AiPreset $targetPreset, string $pluginName, bool $merge = true): bool
    {
        $pluginData = $this->export($sourcePreset, $pluginName);

        if (empty($pluginData)) {
            return true; // Nothing to copy
        }

        return $this->import($targetPreset, $pluginName, $pluginData, $merge);
    }

    /**
     * @inheritDoc
     */
    public function movePlugin(AiPreset $preset, string $fromPluginName, string $toPluginName): bool
    {
        $pluginData = $this->export($preset, $fromPluginName);

        if (empty($pluginData)) {
            return true; // Nothing to move
        }

        // Import to new plugin name
        $importSuccess = $this->import($preset, $toPluginName, $pluginData, false);

        if ($importSuccess) {
            // Clear old plugin data
            return $this->clear($preset, $fromPluginName);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getPluginStats(AiPreset $preset, string $pluginName): array
    {
        $pluginData = $this->export($preset, $pluginName);

        return [
            'plugin_name' => $pluginName,
            'has_metadata' => !empty($pluginData),
            'keys_count' => count($pluginData),
            'keys' => array_keys($pluginData),
            'size_bytes' => strlen(json_encode($pluginData)),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAllPluginStats(AiPreset $preset): array
    {
        $allPluginData = $this->getAllPluginMetadata($preset);
        $stats = [];

        foreach ($allPluginData as $pluginName => $pluginData) {
            $stats[$pluginName] = [
                'keys_count' => count($pluginData),
                'keys' => array_keys($pluginData),
                'size_bytes' => strlen(json_encode($pluginData)),
            ];
        }

        return [
            'total_plugins' => count($allPluginData),
            'total_size_bytes' => strlen(json_encode($allPluginData)),
            'plugins' => $stats,
        ];
    }

    /**
     * @inheritDoc
     */
    public function validatePluginName(string $pluginName): array
    {
        $errors = [];

        if (empty($pluginName)) {
            $errors[] = 'Plugin name cannot be empty';
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
            $errors[] = 'Plugin name can only contain letters, numbers, underscores and hyphens';
        }

        if (strlen($pluginName) > 50) {
            $errors[] = 'Plugin name cannot be longer than 50 characters';
        }

        return $errors;
    }

    /**
     * Get plugin path for metadata key
     *
     * @param string $pluginName
     * @param string|null $key
     * @return string
     */
    protected function getPluginPath(string $pluginName, ?string $key = null): string
    {
        $basePath = self::PLUGINS_NAMESPACE . '.' . $pluginName;

        if ($key === null) {
            return $basePath;
        }

        return $basePath . '.' . $key;
    }

    /**
     * Recursive search helper
     *
     * @param array $data
     * @param [type] $searchValue
     * @param array $results
     * @param string $prefix
     * @param boolean $strict
     * @return void
     */
    protected function searchRecursive(array $data, $searchValue, array &$results, string $prefix, bool $strict): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $this->searchRecursive($value, $searchValue, $results, $fullKey, $strict);
            } else {
                $matches = $strict ? ($value === $searchValue) : ($value == $searchValue);
                if ($matches) {
                    $results[$fullKey] = $value;
                }
            }
        }
    }
}
