<?php

namespace App\Services\Settings;

use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\Option;
use Illuminate\Support\Facades\Cache;

class DbOptionsService implements OptionsServiceInterface
{
    /**
     * Cache prefix
     */
    protected const CACHE_PREFIX = 'options_';

    /**
     * Cache lifetime (1 day)
     */
    protected const CACHE_TTL = 86400;

    /**
     * Get option value by key
     *
     * @param string $key Option key
     * @param mixed $default Default key if option not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        // Try to get from cache
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
            $option = Option::where('key', $key)->first();

            if (!$option) {
                return $default;
            }

            // Cast value depends of its type
            return $this->castValue($option->value, $option->type);
        });
    }

    /**
     * Set option value
     *
     * @param string $key Option key
     * @param mixed $value Option value
     * @return bool
     */
    public function set(string $key, $value): bool
    {
        $type = $this->getValueType($value);
        $serializedValue = $this->serializeValue($value, $type);

        $option = Option::updateOrCreate(
            ['key' => $key],
            [
                'value' => $serializedValue,
                'type' => $type
            ]
        );

        // Kill the cache
        $this->clearCache($key);

        return (bool) $option;
    }

    /**
     * Check if option exists
     *
     * @param string $key Option key
     * @return bool
     */
    public function has(string $key): bool
    {
        return Option::where('key', $key)->exists();
    }

    /**
     * Remove option by key
     *
     * @param string $key Option key
     * @return bool
     * @throws \Exception When trying to remove system option
     */
    public function remove(string $key): bool
    {
        // Check if the option is a system one
        $option = Option::where('key', $key)->first();

        if ($option && $option->is_system) {
            throw new \Exception("Cannot remove system option '{$key}'. System options are protected from deletion.");
        }

        $result = Option::where('key', $key)->delete();

        // Clear cache for this key
        $this->clearCache($key);

        return (bool) $result;
    }

    /**
     * Get value type
     *
     * @param mixed $value
     * @return string
     */
    protected function getValueType($value): string
    {
        if (is_null($value)) {
            return 'null';
        } elseif (is_bool($value)) {
            return 'boolean';
        } elseif (is_int($value)) {
            return 'integer';
        } elseif (is_float($value)) {
            return 'float';
        } elseif (is_array($value) || is_object($value)) {
            return 'array';
        } else {
            return 'string';
        }
    }

    /**
     * Serialize value to store
     *
     * @param mixed $value
     * @param string $type
     * @return string
     */
    protected function serializeValue($value, string $type): string
    {
        if ($type === 'array') {
            return json_encode($value);
        } elseif ($type === 'boolean') {
            return $value ? '1' : '0';
        } elseif ($type === 'null') {
            return '';
        } else {
            return (string) $value;
        }
    }

    /**
     * Cast value to specific type
     *
     * @param string $value
     * @param string $type
     * @return mixed
     */
    protected function castValue(string $value, string $type)
    {
        switch ($type) {
            case 'null':
                return null;
            case 'boolean':
                return $value === '1';
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'array':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * Clear cache for specific key
     *
     * @param string $key
     * @return void
     */
    protected function clearCache(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX . $key);
    }
}
