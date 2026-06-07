<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;

/**
 * High-level vision service.
 *
 * Single entry point for "describe this image" across the app.
 * Resolves the configured provider for a preset via VisionRegistry and
 * delegates the actual API call. Returns null on any failure so callers
 * can degrade gracefully (e.g. MCP media falling back to a stub note).
 *
 * No cache: images are effectively unique and the resulting description is
 * persisted by the agent (or pushed to the input pool) anyway, so caching the
 * raw base64→text mapping buys nothing.
 */
interface VisionServiceInterface
{
    /**
     * Describe an image using the preset's active vision provider.
     *
     * @param  ImageData    $image
     * @param  string|null  $query   Optional guiding question.
     * @param  AiPreset     $preset
     * @return string|null           Description text, or null if unavailable/failed.
     */
    public function describe(ImageData $image, ?string $query, AiPreset $preset): ?string;

    /**
     * Whether a usable vision config exists for the preset.
     */
    public function isAvailable(AiPreset $preset): bool;

    /**
     * Whether recognized media should be pushed into the input pool
     * (vs. returned inline as a tool result). Reads the 'send_to_pool'
     * flag from the preset's active vision capability config.
     *
     * Lets callers (e.g. McpPlugin) decide routing without touching
     * capability storage directly.
     */
    public function shouldSendToPool(AiPreset $preset): bool;
}
