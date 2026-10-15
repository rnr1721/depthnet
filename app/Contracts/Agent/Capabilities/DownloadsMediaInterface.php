<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Optional interface: save the media of one message to local disk.
 *
 * This is the bridge to the other capabilities: a downloaded voice message
 * goes to SttService, a photo to VisionService. The caller owns the file
 * afterwards and is expected to delete it when done.
 */
interface DownloadsMediaInterface
{
    /**
     * @param  string      $target    Chat address.
     * @param  string      $messageId Message id within that chat.
     * @param  string|null $directory Target directory; null → provider default.
     * @return MessengerResult value: DownloadedMedia
     */
    public function download(string $target, string $messageId, ?string $directory = null): MessengerResult;
}
