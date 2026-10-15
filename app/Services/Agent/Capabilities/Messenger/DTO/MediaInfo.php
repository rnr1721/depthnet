<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * What kind of media a message carries. The file itself is fetched separately
 * via DownloadsMediaInterface, only when someone actually needs it.
 */
final class MediaInfo
{
    public const TYPE_PHOTO      = 'photo';
    public const TYPE_VOICE      = 'voice';
    public const TYPE_VIDEO_NOTE = 'video_note';
    public const TYPE_AUDIO      = 'audio';
    public const TYPE_VIDEO      = 'video';
    public const TYPE_GIF        = 'gif';
    public const TYPE_STICKER    = 'sticker';
    public const TYPE_DOCUMENT   = 'document';
    public const TYPE_LOCATION   = 'location';
    public const TYPE_CONTACT    = 'contact';
    public const TYPE_POLL       = 'poll';
    public const TYPE_WEBPAGE    = 'webpage';
    public const TYPE_OTHER      = 'other';

    public function __construct(
        public readonly string $type,
        public readonly ?string $mime = null,
        public readonly ?int $size = null,
        public readonly ?string $name = null,
        public readonly ?int $duration = null,
    ) {
    }

    /** Spoken audio worth sending to STT. */
    public function isSpeech(): bool
    {
        return $this->type === self::TYPE_VOICE;
    }

    /** A still image worth sending to Vision. */
    public function isImage(): bool
    {
        return $this->type === self::TYPE_PHOTO
            || ($this->type === self::TYPE_DOCUMENT && str_starts_with((string) $this->mime, 'image/'));
    }

    /** Whether there is a file behind it at all. */
    public function isDownloadable(): bool
    {
        return !in_array($this->type, [
            self::TYPE_LOCATION, self::TYPE_CONTACT, self::TYPE_POLL, self::TYPE_WEBPAGE,
        ], true);
    }

    /** "(voice 0:12)", "(photo)", "(document report.pdf)". */
    public function label(): string
    {
        $label = str_replace('_', ' ', $this->type);

        if ($this->duration !== null && $this->duration > 0) {
            $label .= sprintf(' %d:%02d', intdiv($this->duration, 60), $this->duration % 60);
        } elseif ($this->type === self::TYPE_DOCUMENT && $this->name) {
            $label .= ' ' . $this->name;
        }

        return '(' . $label . ')';
    }

    public function toArray(): array
    {
        return array_filter([
            'type'     => $this->type,
            'mime'     => $this->mime,
            'size'     => $this->size,
            'name'     => $this->name,
            'duration' => $this->duration,
        ], fn ($v) => $v !== null);
    }
}
