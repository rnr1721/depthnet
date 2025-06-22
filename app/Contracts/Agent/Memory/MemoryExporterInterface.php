<?php

namespace App\Contracts\Agent\Memory;

use App\Models\AiPreset;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface for memory export functionality
 */
interface MemoryExporterInterface
{
    /**
     * Export memory content to downloadable format
     *
     * @param AiPreset $preset
     * @param string $content
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function export(AiPreset $preset, string $content): Response;

    /**
     * Generate filename for export
     *
     * @param AiPreset $preset
     * @return string
     */
    public function generateFilename(AiPreset $preset): string;
}
