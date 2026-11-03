<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

use App\Services\Agent\Capabilities\Messenger\DTO\MediaInfo;

/**
 * What the agent gets instead of a raw media file: a transcript, an image
 * description, or a plain explanation of why there is neither.
 */
final class MediaInterpretation
{
    public const KIND_TRANSCRIPT   = 'transcript';
    public const KIND_DESCRIPTION  = 'description';
    public const KIND_UNAVAILABLE  = 'unavailable';

    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly ?MediaInfo $media = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->kind !== self::KIND_UNAVAILABLE;
    }

    /** "transcript: …" / "image description: …" / "(could not transcribe: …)" */
    public function render(): string
    {
        return match ($this->kind) {
            self::KIND_TRANSCRIPT  => 'transcript: ' . $this->text,
            self::KIND_DESCRIPTION => 'image description: ' . $this->text,
            default                => '(' . $this->text . ')',
        };
    }
}
