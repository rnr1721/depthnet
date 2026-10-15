<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

use App\Contracts\Agent\Capabilities\DownloadsMediaInterface;
use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Contracts\Agent\Capabilities\SttServiceInterface;
use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Messenger\DTO\MediaInfo;
use App\Services\Agent\Capabilities\Speech\DTO\AudioData;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use Psr\Log\LoggerInterface;

/**
 * Turns a message's media into text the agent can perceive, by composing the
 * other capabilities: voice → SttService, image → VisionService.
 *
 * The messenger itself knows nothing about speech or vision; it only downloads.
 * This is the one place where the three meet, used both by the inbound router
 * (automatically, for incoming voice/photos) and by MessengerPlugin's `media`
 * command (on demand, for something seen in a chat history).
 *
 * Files are downloaded into a private temp dir per call and always removed —
 * only text survives, same rule as the vision and speech layers.
 *
 * Returns null when there is nothing to do (media type not interpretable, or
 * the capability that would interpret it isn't available) — the caller then
 * just shows the media label. Returns an UNAVAILABLE interpretation when an
 * attempt was made and failed, so the agent learns why.
 */
class MediaInterpreter
{
    public function __construct(
        protected SttServiceInterface $stt,
        protected VisionServiceInterface $vision,
        protected LoggerInterface $logger,
        protected ?string $tempBase = null,
    ) {
    }

    /**
     * Whether this preset could interpret this kind of media at all — cheap,
     * no download. Lets callers skip media nobody can read (videos, files).
     */
    public function canInterpret(AiPreset $preset, MediaInfo $media): bool
    {
        if ($media->isSpeech()) {
            return $this->stt->isServerSideAvailable($preset);
        }
        if ($media->isImage()) {
            return $this->vision->isAvailable($preset);
        }

        return false;
    }

    /**
     * @param MediaInfo|null $known    Media info when already known (inbound); null when
     *                                 the caller only has chat + id (plugin command).
     * @param string|null    $question Optional question for images ("what's the total?").
     */
    public function interpret(
        AiPreset $preset,
        MessengerProviderInterface $provider,
        string $chatAddress,
        string $messageId,
        ?MediaInfo $known = null,
        ?string $question = null,
    ): ?MediaInterpretation {
        if ($known !== null && !$this->canInterpret($preset, $known)) {
            return null;
        }

        if (!$provider instanceof DownloadsMediaInterface) {
            return new MediaInterpretation(
                MediaInterpretation::KIND_UNAVAILABLE,
                $provider->getDisplayName() . ' cannot download media',
                $known,
            );
        }

        $dir = $this->makeTempDir((int) $preset->getId());

        try {
            $download = $provider->download($chatAddress, $messageId, $dir);

            if (!$download->success) {
                return new MediaInterpretation(
                    MediaInterpretation::KIND_UNAVAILABLE,
                    'could not download: ' . $download->error,
                    $known,
                );
            }

            $file  = $download->value;
            $media = $known ?? $file->media;

            if ($media->isSpeech() && $this->stt->isServerSideAvailable($preset)) {
                return $this->transcribe($preset, $file->path, $media);
            }

            if ($media->isImage() && $this->vision->isAvailable($preset)) {
                return $this->describe($preset, $file->path, $media, $question);
            }

            return new MediaInterpretation(
                MediaInterpretation::KIND_UNAVAILABLE,
                $this->whyNot($preset, $media),
                $media,
            );
        } catch (\Throwable $e) {
            $this->logger->error('MediaInterpreter: failed', [
                'preset_id'  => $preset->getId(),
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
            return new MediaInterpretation(
                MediaInterpretation::KIND_UNAVAILABLE,
                'could not process media: ' . $e->getMessage(),
                $known,
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    private function transcribe(AiPreset $preset, string $path, MediaInfo $media): MediaInterpretation
    {
        $audio = AudioData::fromPath($path, 'messenger:' . $media->type);

        // mime_content_type() sometimes says application/octet-stream for Opus
        // in an Ogg container; the messenger's own mime is more reliable then.
        if (!str_starts_with($audio->getMimeType(), 'audio/') && $media->mime) {
            $audio = AudioData::fromBinary($audio->getBytes(), $media->mime, $audio->getSourceLabel(), $media->duration);
        }

        $result = $this->stt->transcribeResult($audio, $preset);

        return $result->success && trim((string) $result->text) !== ''
            ? new MediaInterpretation(MediaInterpretation::KIND_TRANSCRIPT, trim((string) $result->text), $media)
            : new MediaInterpretation(
                MediaInterpretation::KIND_UNAVAILABLE,
                'could not transcribe: ' . ($result->error ?: 'empty transcript'),
                $media,
            );
    }

    private function describe(AiPreset $preset, string $path, MediaInfo $media, ?string $question): MediaInterpretation
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return new MediaInterpretation(MediaInterpretation::KIND_UNAVAILABLE, 'downloaded image is empty', $media);
        }

        $mime = $media->mime ?: ((function_exists('mime_content_type') ? @mime_content_type($path) : null) ?: 'image/jpeg');

        $result = $this->vision->describeResult(
            ImageData::fromBinary($bytes, $mime, 'messenger:' . $media->type),
            $question,
            $preset,
        );

        return $result->success
            ? new MediaInterpretation(MediaInterpretation::KIND_DESCRIPTION, trim((string) $result->text), $media)
            : new MediaInterpretation(MediaInterpretation::KIND_UNAVAILABLE, 'could not describe: ' . $result->error, $media);
    }

    private function whyNot(AiPreset $preset, MediaInfo $media): string
    {
        if ($media->isSpeech()) {
            return 'speech recognition is not available on the server for this agent';
        }
        if ($media->isImage()) {
            return 'vision is not configured for this agent';
        }

        return "no interpretation for {$media->type}" . ($media->name ? " ({$media->name})" : '');
    }

    private function makeTempDir(int $presetId): string
    {
        $base = rtrim($this->tempBase ?: sys_get_temp_dir() . '/depthnet-messenger', '/');
        $dir  = $base . '/preset_' . $presetId . '/' . bin2hex(random_bytes(6));

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create temp dir {$dir}");
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
            is_dir($f) ? $this->removeDir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
