<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Vision\DTO\ImageData;

/**
 * Contract for vision capability providers.
 *
 * Extends the base capability contract with a single vision-specific
 * operation: turning an image into a textual description.
 *
 * Providers do NOT reuse the chat engines (ClaudeModel/DeepSeekModel).
 * They make their own narrow HTTP call — one image + one optional question
 * in, one text description out — keeping the capability layer independent
 * of the conversational pipeline (same philosophy as the embedding providers).
 */
interface VisionProviderInterface extends CapabilityProviderInterface
{
    /**
     * Capability type identifier stored in preset_capability_configs.capability.
     */
    public const CAPABILITY = 'vision';

    /**
     * Describe an image, optionally guided by a question.
     *
     * @param  ImageData    $image  Normalized image (base64 + mime).
     * @param  string|null  $query  Optional question. Null/empty → general description.
     * @return string|null          Description text, or null on failure.
     */
    public function describe(ImageData $image, ?string $query = null): ?string;
}
