<?php

namespace App\Contracts\Agent\VectorMemory;

use App\Models\AiPreset;
use Illuminate\Support\Collection;

/**
 * Interface for vector memory export operations
 */
interface VectorMemoryExporterInterface
{
    /**
     * Export vector memories as download response
     *
     * @param AiPreset $preset
     * @param Collection $memories
     * @return array
     */
    public function export(AiPreset $preset, Collection $memories): array;
}
