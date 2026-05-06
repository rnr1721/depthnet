<?php

namespace App\Services\Agent\Plugins\DTO;

use App\Models\AiPreset;

/**
 * PluginExecutionContext — immutable bundle of "everything a plugin needs
 * to know about the current execution".
 *
 * Replaces the stateful $this->config + implicit "current preset" coupling
 * that PluginManager used to maintain. Now every call to a plugin method
 * receives an explicit context, and the plugin reads from it instead of
 * its own fields.
 *
 * Currently carries:
 *   - preset:  the AiPreset this execution belongs to
 *   - config:  the resolved plugin config for this preset (merged from
 *              PresetPluginConfig with defaults from getDefaultConfig())
 *   - enabled: whether the plugin is enabled for this preset
 *
 * Immutable by design (readonly properties): a context is built once by
 * PluginExecutionContextBuilder, passed down, and never mutated. If a
 * plugin needs a modified context, it should ask the builder for a new one
 * — not mutate the existing one.
 */
final class PluginExecutionContext
{
    private array $meta = [];

    public function __construct(
        public readonly AiPreset $preset,
        public readonly array $config,
        public readonly bool $enabled,
    ) {
    }

    /**
     * Convenience accessor for a single config value with default fallback.
     * Saves callers from writing $context->config['foo'] ?? 'bar' everywhere.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function addMeta(string $key, mixed $value, bool $merge = false): void
    {
        if ($merge) {
            $existing = $this->meta[$key] ?? [];
            $this->meta[$key] = array_values(array_unique(array_merge($existing, (array)$value)));
            return;
        }

        $this->meta[$key] = $value;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

}
