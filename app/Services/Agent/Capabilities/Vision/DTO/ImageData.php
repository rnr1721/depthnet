<?php

namespace App\Services\Agent\Capabilities\Vision\DTO;

/**
 * Normalized image carrier passed from an image source to a vision provider.
 *
 * Holds raw base64 (WITHOUT the "data:...;base64," prefix) plus the mime type.
 * Different providers want different shapes, so this DTO exposes both:
 *
 *  - toDataUri()  → "data:image/jpeg;base64,...."   (OpenAI/DeepSeek image_url)
 *  - getBase64()  → raw base64 string               (Anthropic source.data)
 *
 * The optional source label is purely informational (e.g. "phone:camera",
 * "upload", "url") — handy for logging and for the text the agent later stores
 * in vector memory. It is never sent to the API.
 *
 * base64 lives only inside the capability layer and is discarded after the
 * description is produced — only the resulting text survives.
 */
final class ImageData
{
    /**
     * @param string      $base64      Raw base64 (no data: prefix).
     * @param string      $mimeType    e.g. 'image/jpeg', 'image/png', 'image/webp'.
     * @param string|null $sourceLabel Optional human-readable origin tag.
     */
    public function __construct(
        public readonly string $base64,
        public readonly string $mimeType = 'image/jpeg',
        public readonly ?string $sourceLabel = null,
    ) {
    }

    /**
     * Build from an already-formed data URI ("data:image/png;base64,....").
     * Falls back to the given default mime if the URI has no media type.
     */
    public static function fromDataUri(string $dataUri, ?string $sourceLabel = null): self
    {
        if (preg_match('#^data:([^;]+);base64,(.*)$#s', $dataUri, $m)) {
            return new self($m[2], $m[1], $sourceLabel);
        }

        // Not a data URI — treat the whole thing as raw base64.
        return new self($dataUri, 'image/jpeg', $sourceLabel);
    }

    /**
     * Build from raw binary bytes (e.g. file_get_contents()).
     */
    public static function fromBinary(string $bytes, string $mimeType = 'image/jpeg', ?string $sourceLabel = null): self
    {
        return new self(base64_encode($bytes), $mimeType, $sourceLabel);
    }

    public function getBase64(): string
    {
        return $this->base64;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSourceLabel(): ?string
    {
        return $this->sourceLabel;
    }

    /**
     * Data URI form used by OpenAI-compatible image_url blocks (DeepSeek, etc.).
     */
    public function toDataUri(): string
    {
        return sprintf('data:%s;base64,%s', $this->mimeType, $this->base64);
    }

    /**
     * Approximate decoded byte size — useful for size-limit guards.
     */
    public function approximateBytes(): int
    {
        return (int) (strlen($this->base64) * 3 / 4);
    }
}
