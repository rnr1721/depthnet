<?php

namespace App\Contracts\Agent\VectorMemory;

use App\Models\AiPreset;
use Closure;

/**
 * Interface for vector memory import operations
 */
interface VectorMemoryImporterInterface
{
    /**
     * Import vector memories from content
     *
     * @param AiPreset $preset
     * @param string $content
     * @param bool $isJson
     * @param bool $replaceExisting
     * @param array $config
     * @param Closure $storeMemoryCallback
     * @return array
     */
    public function importFromContent(
        AiPreset $preset,
        string $content,
        bool $isJson,
        bool $replaceExisting,
        array $config,
        Closure $storeMemoryCallback
    ): array;
}
