<?php

namespace App\Contracts\Agent\Memory;

use App\Models\AiPreset;
use Illuminate\Http\Request;

/**
 * Interface for memory import functionality
 */
interface MemoryImporterInterface
{
    /**
     * Import memory content from request
     *
     * @param AiPreset $preset
     * @param Request $request
     * @param array $config
     * @return array
     */
    public function import(AiPreset $preset, Request $request, array $config): array;

    /**
     * Extract content from request (file or direct input)
     *
     * @param Request $request
     * @return string|null
     */
    public function extractContent(Request $request): ?string;

    /**
     * Validate import content
     *
     * @param string $content
     * @return bool
     */
    public function validateContent(string $content): bool;
}
