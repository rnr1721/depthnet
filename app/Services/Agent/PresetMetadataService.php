<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PresetMetadataServiceInterface;
use App\Models\AiPreset;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * Service for managing AI preset metadata
 *
 * Provides centralized access to preset metadata with validation,
 * plugin support, and transaction safety.
 */
class PresetMetadataService implements PresetMetadataServiceInterface
{
    public function __construct(
        protected DatabaseManager $db,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(AiPreset $preset, ?string $key = null, $default = null)
    {
        $metadata = $preset->metadata ?? [];

        if ($key === null) {
            return $metadata;
        }

        // Support dot notation for nested keys
        if (strpos($key, '.') !== false) {
            return data_get($metadata, $key, $default);
        }

        return $metadata[$key] ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function set(AiPreset $preset, string $key, $value): bool
    {
        return $this->db->transaction(function () use ($preset, $key, $value) {
            $metadata = $preset->metadata ?? [];

            if (strpos($key, '.') !== false) {
                data_set($metadata, $key, $value);
            } else {
                $metadata[$key] = $value;
            }

            $preset->metadata = $metadata;
            $result = $preset->save();

            $this->logMetadataChange($preset, 'set', $key, $value);

            return $result;
        });
    }

    /**
     * @inheritDoc
     */
    public function update(AiPreset $preset, array $data): bool
    {
        return $this->db->transaction(function () use ($preset, $data) {
            $metadata = $preset->metadata ?? [];

            foreach ($data as $key => $value) {
                if (strpos($key, '.') !== false) {
                    data_set($metadata, $key, $value);
                } else {
                    $metadata[$key] = $value;
                }
            }

            $preset->metadata = $metadata;
            $result = $preset->save();

            $this->logMetadataChange($preset, 'update', array_keys($data), $data);

            return $result;
        });
    }

    /**
     * @inheritDoc
     */
    public function remove(AiPreset $preset, string $key): bool
    {
        return $this->db->transaction(function () use ($preset, $key) {
            $metadata = $preset->metadata ?? [];

            if (strpos($key, '.') !== false) {
                data_forget($metadata, $key);
            } else {
                unset($metadata[$key]);
            }

            $preset->metadata = $metadata;
            $result = $preset->save();

            $this->logMetadataChange($preset, 'remove', $key, null);

            return $result;
        });
    }

    /**
     * @inheritDoc
     */
    public function has(AiPreset $preset, string $key): bool
    {
        $metadata = $preset->metadata ?? [];

        if (strpos($key, '.') !== false) {
            return data_get($metadata, $key) !== null;
        }

        return isset($metadata[$key]);
    }

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset, ?string $namespace = null): bool
    {
        return $this->db->transaction(function () use ($preset, $namespace) {
            if ($namespace === null) {
                $preset->metadata = [];
            } else {
                $metadata = $preset->metadata ?? [];
                unset($metadata[$namespace]);
                $preset->metadata = $metadata;
            }

            $result = $preset->save();

            $this->logMetadataChange($preset, 'clear', $namespace ?? 'all', null);

            return $result;
        });
    }

    /**
     * @inheritDoc
     */
    public function getPluginMetadata(AiPreset $preset, string $pluginName, ?string $key = null, $default = null)
    {
        $pluginData = $this->get($preset, $pluginName, []);

        if ($key === null) {
            return $pluginData;
        }

        return $pluginData[$key] ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function setPluginMetadata(AiPreset $preset, string $pluginName, string $key, $value): bool
    {
        return $this->set($preset, "{$pluginName}.{$key}", $value);
    }

    /**
     * @inheritDoc
     */
    public function updatePluginMetadata(AiPreset $preset, string $pluginName, array $data): bool
    {
        $pluginData = $this->get($preset, $pluginName, []);
        $pluginData = array_merge($pluginData, $data);

        return $this->set($preset, $pluginName, $pluginData);
    }

    /**
     * @inheritDoc
     */
    public function removePluginMetadata(AiPreset $preset, string $pluginName, string $key): bool
    {
        return $this->remove($preset, "{$pluginName}.{$key}");
    }

    /**
     * @inheritDoc
     */
    public function clearPluginMetadata(AiPreset $preset, string $pluginName): bool
    {
        return $this->clear($preset, $pluginName);
    }

    /**
     * @inheritDoc
     */
    public function hasPluginMetadata(AiPreset $preset, string $pluginName, ?string $key = null): bool
    {
        if ($key === null) {
            return $this->has($preset, $pluginName);
        }

        return $this->has($preset, "{$pluginName}.{$key}");
    }

    /**
     * @inheritDoc
     */
    public function increment(AiPreset $preset, string $key, int $amount = 1, ?int $max = null): bool
    {
        return $this->db->transaction(function () use ($preset, $key, $amount, $max) {
            $currentValue = (int) $this->get($preset, $key, 0);
            $newValue = $currentValue + $amount;

            if ($max !== null && $newValue > $max) {
                $newValue = $max;
            }

            return $this->set($preset, $key, $newValue);
        });
    }

    /**
     * @inheritDoc
     */
    public function decrement(AiPreset $preset, string $key, int $amount = 1, ?int $min = null): bool
    {
        return $this->db->transaction(function () use ($preset, $key, $amount, $min) {
            $currentValue = (int) $this->get($preset, $key, 0);
            $newValue = $currentValue - $amount;

            if ($min !== null && $newValue < $min) {
                $newValue = $min;
            }

            return $this->set($preset, $key, $newValue);
        });
    }

    /**
     * @inheritDoc
     */
    public function setNumeric(AiPreset $preset, string $key, int $value, ?int $min = null, ?int $max = null): bool
    {
        if ($min !== null && $value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $this->set($preset, $key, $value);
    }

    /**
     * @inheritDoc
     */
    public function getKeys(AiPreset $preset, ?string $namespace = null): array
    {
        $metadata = $preset->metadata ?? [];

        if ($namespace === null) {
            return array_keys($metadata);
        }

        $namespaceData = $metadata[$namespace] ?? [];
        return array_keys($namespaceData);
    }

    /**
     * @inheritDoc
     */
    public function export(AiPreset $preset, ?string $namespace = null): array
    {
        if ($namespace === null) {
            return $preset->metadata ?? [];
        }

        return $this->get($preset, $namespace, []);
    }

    /**
     * @inheritDoc
     */
    public function import(AiPreset $preset, array $metadata, ?string $namespace = null, bool $merge = true): bool
    {
        if ($namespace === null) {
            if ($merge) {
                $existingMetadata = $preset->metadata ?? [];
                $metadata = array_merge($existingMetadata, $metadata);
            }

            return $this->db->transaction(function () use ($preset, $metadata) {
                $preset->metadata = $metadata;
                $result = $preset->save();

                $this->logMetadataChange($preset, 'import', 'all', $metadata);

                return $result;
            });
        }

        if ($merge) {
            $existingData = $this->get($preset, $namespace, []);
            $metadata = array_merge($existingData, $metadata);
        }

        return $this->set($preset, $namespace, $metadata);
    }

    /**
     * @inheritDoc
     */
    public function validate(array $metadata): array
    {
        $errors = [];

        // Check for reserved keys
        $reservedKeys = ['id', 'name', 'engine_name', 'created_at', 'updated_at'];

        foreach ($metadata as $key => $value) {
            if (in_array($key, $reservedKeys)) {
                $errors[] = "Key '{$key}' is reserved and cannot be used in metadata";
            }

            // Check key format
            if (!is_string($key) || empty($key)) {
                $errors[] = "Metadata keys must be non-empty strings";
            }

            // Check for valid JSON serializable values
            if (!$this->isJsonSerializable($value)) {
                $errors[] = "Value for key '{$key}' is not JSON serializable";
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getSize(AiPreset $preset): int
    {
        $metadata = $preset->metadata ?? [];
        return strlen(json_encode($metadata));
    }

    /**
     * @inheritDoc
     */
    public function exceedsLimit(AiPreset $preset, int $limitBytes = 65535): bool
    {
        return $this->getSize($preset) > $limitBytes;
    }

    /**
     * @inheritDoc
     */
    public function search(AiPreset $preset, $searchValue, bool $strict = false): array
    {
        $metadata = $preset->metadata ?? [];
        $results = [];

        $this->searchRecursive($metadata, $searchValue, $results, '', $strict);

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function merge(AiPreset $targetPreset, AiPreset $sourcePreset, ?string $namespace = null): bool
    {
        $sourceMetadata = $this->export($sourcePreset, $namespace);

        return $this->import($targetPreset, $sourceMetadata, $namespace, true);
    }

    /**
     * @inheritDoc
     */
    public function backup(AiPreset $preset): array
    {
        return [
            'preset_id' => $preset->id,
            'preset_name' => $preset->name,
            'metadata' => $preset->metadata ?? [],
            'backup_timestamp' => now()->toISOString(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function restore(AiPreset $preset, array $backup): bool
    {
        if (!isset($backup['metadata'])) {
            throw new \InvalidArgumentException('Invalid backup format: missing metadata');
        }

        $validationErrors = $this->validate($backup['metadata']);
        if (!empty($validationErrors)) {
            throw new \InvalidArgumentException('Invalid metadata in backup: ' . implode(', ', $validationErrors));
        }

        return $this->import($preset, $backup['metadata'], null, false);
    }

    /**
     * Log metadata changes
     *
     * @param AiPreset $preset
     * @param string $action
     * @param [type] $key
     * @param [type] $value
     * @return void
     */
    protected function logMetadataChange(AiPreset $preset, string $action, $key, $value): void
    {
        $this->logger->info('Metadata changed', [
            'preset_id' => $preset->id,
            'preset_name' => $preset->name,
            'action' => $action,
            'key' => $key,
            'value_type' => gettype($value),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Check if value is JSON serializable
     *
     * @param [type] $value
     * @return boolean
     */
    protected function isJsonSerializable($value): bool
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
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
