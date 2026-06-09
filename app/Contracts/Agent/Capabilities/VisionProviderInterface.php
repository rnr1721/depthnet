<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\Capabilities\Vision\DTO\VisionResult;

/**
 * Contract for vision capability providers.
 *
 * Providers make their own narrow HTTP call — one image + one optional question
 * in, one description out — independent of the chat engines.
 */
interface VisionProviderInterface extends CapabilityProviderInterface
{
    public const CAPABILITY = 'vision';

    /**
     * Describe an image, returning a structured result that carries either the
     * description or a human-readable failure reason.
     *
     * Implementations MUST NOT throw for ordinary API failures (bad key, model
     * not found, timeout) — they return VisionResult::fail() with a concise,
     * human-facing reason. Only truly unexpected errors may surface as throws,
     * which VisionService will catch.
     *
     * @param  ImageData    $image
     * @param  string|null  $query  Optional question. Null/empty → general description.
     */
    public function describeResult(ImageData $image, ?string $query = null): VisionResult;
}
