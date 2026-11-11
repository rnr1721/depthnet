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
        return $this->mutate($preset, 'set', $key, function (array $m) use ($key, $value) {
            data_set($m, $key, $value);
            return $m;
        });
    }

    /**
     * @inheritDoc
     */
    public function update(AiPreset $preset, array $data): bool
    {
        return $this->mutate($preset, 'update', array_keys($data), function (array $m) use ($data) {
            foreach ($data as $key => $value) {
                data_set($m, $key, $value);
            }
            return $m;
        });
    }

    /**
     * @inheritDoc
     */
    public function remove(AiPreset $preset, string $key): bool
    {
        return $this->mutate($preset, 'remove', $key, function (array $m) use ($key) {
            data_forget($m, $key);
            return $m;
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
        return $this->mutate($preset, 'clear', $namespace ?? 'all', function (array $m) use ($namespace) {
            if ($namespace === null) {
                return [];
            }
            unset($m[$namespace]);
            return $m;
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
        $paths = [];
        foreach ($data as $k => $v) {
            $paths["{$pluginName}.{$k}"] = $v;
        }

        return $this->update($preset, $paths);
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
        return $this->mutate($preset, 'increment', $key, function (array $m) use ($key, $amount, $max) {
            $v = (int) data_get($m, $key, 0) + $amount;
            data_set($m, $key, $max !== null ? min($v, $max) : $v);
            return $m;
        });
    }

    /**
     * @inheritDoc
     */
    public function decrement(AiPreset $preset, string $key, int $amount = 1, ?int $min = null): bool
    {
        return $this->mutate($preset, 'decrement', $key, function (array $m) use ($key, $amount, $min) {
            $v = (int) data_get($m, $key, 0) - $amount;
            data_set($m, $key, $min !== null ? max($v, $min) : $v);
            return $m;
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
        return $this->mutate($preset, 'import', $namespace ?? 'all', function (array $m) use ($metadata, $namespace, $merge) {
            if ($namespace === null) {
                return $merge ? array_merge($m, $metadata) : $metadata;
            }
            $existing = $merge ? (array) data_get($m, $namespace, []) : [];
            data_set($m, $namespace, array_merge($existing, $metadata));
            return $m;
        });
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

    /**
     * Read-modify-write on the CURRENT row, not on this process's copy.
     * Locks the preset row so concurrent writers (agent cycle, poller, UI)
     * serialize instead of overwriting each other's keys.
     */
    private function mutate(AiPreset $preset, string $action, $key, callable $change): bool
    {
        return $this->db->transaction(function () use ($preset, $action, $key, $change) {
            $raw = AiPreset::whereKey($preset->getKey())->lockForUpdate()->value('metadata');

            $metadata = is_string($raw) ? (json_decode($raw, true) ?: []) : ((array) ($raw ?? []));
            $metadata = $change($metadata);

            $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            AiPreset::whereKey($preset->getKey())->update(['metadata' => $json]);

            $preset->forceFill(['metadata' => $metadata]);
            $preset->syncOriginalAttribute('metadata');

            $this->logMetadataChange($preset, $action, $key, null);

            return true;
        });
    }
}
