<?php

namespace App\Services\Agent\Capabilities\Speech\DTO;

/**
 * Audio carrier used on both sides of the speech capabilities.
 *
 * STT: what a channel hands to the provider (browser recording, Telegram voice
 *      message, uploaded file).
 * TTS: what a provider hands back to the caller.
 *
 * Unlike ImageData this keeps RAW BYTES as the primary representation, not
 * base64. STT providers send audio as multipart/form-data, so base64 would mean
 * encoding and immediately decoding on every request. base64 is still available
 * for the cases that want it (JSON transport, storing in a message payload),
 * just computed on demand.
 *
 * Audio lives only inside the capability layer and is discarded once the
 * transcription (or the played sound) is produced — only text survives.
 */
final class AudioData
{
    /**
     * Extension per mime type, used to name the multipart part. OpenAI-compatible
     * transcription endpoints sniff the format from the FILENAME, not from the
     * Content-Type header, so getting this wrong makes a valid request fail.
     */
    private const MIME_EXTENSIONS = [
        'audio/webm'   => 'webm',
        'audio/ogg'    => 'ogg',
        'audio/oga'    => 'ogg',
        'audio/opus'   => 'ogg',
        'audio/wav'    => 'wav',
        'audio/x-wav'  => 'wav',
        'audio/wave'   => 'wav',
        'audio/mpeg'   => 'mp3',
        'audio/mp3'    => 'mp3',
        'audio/mp4'    => 'm4a',
        'audio/m4a'    => 'm4a',
        'audio/x-m4a'  => 'm4a',
        'audio/flac'   => 'flac',
        'audio/aac'    => 'aac',
    ];

    /**
     * @param string      $bytes       Raw audio bytes.
     * @param string      $mimeType    e.g. 'audio/webm', 'audio/ogg', 'audio/wav'.
     * @param string|null $sourceLabel Optional origin tag ('browser', 'telegram:voice').
     *                                 Informational only — never sent to a provider.
     * @param float|null  $durationSec Optional duration, when the caller knows it.
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType = 'audio/webm',
        public readonly ?string $sourceLabel = null,
        public readonly ?float $durationSec = null,
    ) {
    }

    public static function fromBinary(
        string $bytes,
        string $mimeType = 'audio/webm',
        ?string $sourceLabel = null,
        ?float $durationSec = null,
    ): self {
        return new self($bytes, $mimeType, $sourceLabel, $durationSec);
    }

    /**
     * Build from base64, with or without a "data:audio/webm;base64," prefix.
     * The prefix wins over $mimeType when present, since it is more specific.
     */
    public static function fromBase64(
        string $base64,
        string $mimeType = 'audio/webm',
        ?string $sourceLabel = null,
    ): self {
        if (preg_match('#^data:([^;]+);base64,(.*)$#s', $base64, $m)) {
            return new self(base64_decode($m[2], true) ?: '', $m[1], $sourceLabel);
        }

        return new self(base64_decode($base64, true) ?: '', $mimeType, $sourceLabel);
    }

    /**
     * Build from an uploaded file, taking the mime from the file itself.
     */
    public static function fromPath(string $path, ?string $sourceLabel = null): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read audio file: {$path}");
        }

        $mime = null;
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path) ?: null;
        }

        return new self($bytes, $mime ?? 'audio/webm', $sourceLabel);
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSourceLabel(): ?string
    {
        return $this->sourceLabel;
    }

    public function getDurationSec(): ?float
    {
        return $this->durationSec;
    }

    public function getBase64(): string
    {
        return base64_encode($this->bytes);
    }

    public function toDataUri(): string
    {
        return sprintf('data:%s;base64,%s', $this->mimeType, $this->getBase64());
    }

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }

    public function isEmpty(): bool
    {
        return $this->bytes === '';
    }

    /**
     * Filename for the multipart part. See MIME_EXTENSIONS — the extension is
     * what the remote endpoint actually uses to pick a decoder.
     */
    public function suggestedFilename(string $stem = 'audio'): string
    {
        $mime = strtolower(explode(';', $this->mimeType)[0]);
        $ext = self::MIME_EXTENSIONS[$mime] ?? 'webm';

        return "{$stem}.{$ext}";
    }
}
