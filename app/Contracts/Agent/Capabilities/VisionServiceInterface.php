<?php

namespace App\Contracts\Agent\Capabilities;

use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\Capabilities\Vision\DTO\VisionResult;

/**
 * High-level vision service.
 *
 * describeResult() — structured (success/text/error), lets callers surface why
 *                    vision failed.
 * describe()       — legacy ?string facade over describeResult().
 *
 * Normalization is applied internally before the provider call. Returns failure
 * reasons rather than silent nulls where callers ask for them.
 */
interface VisionServiceInterface
{
    /**
     * Structured describe — carries a human-readable reason on failure.
     */
    public function describeResult(ImageData $image, ?string $query, AiPreset $preset): VisionResult;

    /**
     * Legacy facade: description text, or null on any failure.
     */
    public function describe(ImageData $image, ?string $query, AiPreset $preset): ?string;

    /**
     * Whether a usable vision config exists for the preset.
     */
    public function isAvailable(AiPreset $preset): bool;

    /**
     * Whether recognized media should be routed into the input pool.
     */
    public function shouldSendToPool(AiPreset $preset): bool;
}
