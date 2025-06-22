<?php

namespace App\Services\Agent\Memory;

use Symfony\Component\HttpFoundation\Response;
use App\Contracts\Agent\Memory\MemoryExporterInterface;
use App\Models\AiPreset;

/**
 * Service for exporting memory content as text files
 */
class TextMemoryExporter implements MemoryExporterInterface
{
    /**
     * @inheritDoc
     */
    public function export(AiPreset $preset, string $content): Response
    {
        $filename = $this->generateFilename($preset);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * @inheritDoc
     */
    public function generateFilename(AiPreset $preset): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $preset->name);
        $timestamp = date('Y-m-d_H-i-s');

        return "memory_preset_{$preset->id}_{$safeName}_{$timestamp}.txt";
    }
}
