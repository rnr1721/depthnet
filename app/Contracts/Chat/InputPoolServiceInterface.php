<?php

namespace App\Contracts\Chat;

use App\Models\AiPreset;
use Illuminate\Database\Eloquent\Collection;

interface InputPoolServiceInterface
{
    /**
     * Whether the input pool feature is enabled globally
     *
     * @param AiPreset $preset Preset to check
     * @return bool
     */
    public function isEnabled(AiPreset $preset): bool;

    /**
     * Add or overwrite a source in the pool.
     * If a source with the same name already exists for this preset — it gets overwritten.
     *
     * @param int $presetId
     * @param string $sourceName
     * @param string $content
     * @return void
     */
    public function add(int $presetId, string $sourceName, string $content): void;

    /**
     * Build JSON from all current pool items and clear the pool.
     * Returns null if pool is empty.
     *
     * Result format:
     * {
     *   "sources": [
     *     {"source": "message_from_user John:", "content": "..."},
     *     {"source": "webhook_weather",          "content": "..."}
     *   ]
     * }
     *
     * @param int $presetId
     * @return string|null
     */
    public function flush(int $presetId): ?string;

    /**
     * Clear all pool items for a preset without sending
     *
     * @param int $presetId
     * @return void
     */
    public function clear(int $presetId): void;

    /**
     * Get all current pool items for a preset (useful for UI preview)
     *
     * @param int $presetId
     * @return Collection
     */
    public function getItems(int $presetId): Collection;
}
