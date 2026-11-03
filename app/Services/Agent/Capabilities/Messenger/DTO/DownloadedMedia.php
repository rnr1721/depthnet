<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * A media file saved to local disk. The caller owns the file and deletes it.
 */
final class DownloadedMedia
{
    public function __construct(
        public readonly string $path,
        public readonly MediaInfo $media,
        public readonly Peer $chat,
        public readonly string $messageId,
        public readonly ?int $size = null,
    ) {
    }
}
