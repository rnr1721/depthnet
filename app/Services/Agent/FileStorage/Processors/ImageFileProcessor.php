<?php

namespace App\Services\Agent\FileStorage\Processors;

use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Models\AiPreset;
use App\Models\File;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use Psr\Log\LoggerInterface;

/**
 * Processor for image files (JPEG, PNG, WebP, GIF).
 *
 * Turns an image into searchable text via the preset's vision capability; the
 * description becomes the file's text, chunked and embedded like any document.
 *
 * Error visibility: on a real vision failure (bad key, non-VLM model, etc.) this
 * THROWS with a human-readable reason. AbstractFileProcessor::process() catches
 * it and returns ProcessingResult::fail($reason), so FileService::process() calls
 * markFailed($reason) — the file shows status ❌ and the reason appears in the
 * file's meta/details instead of a silent empty result.
 *
 * Distinguishes "vision not configured" (also a throw — the user should know the
 * upload won't be described) from "model genuinely saw nothing" (rare; treated
 * as failure too, since an image document with no description is useless for RAG).
 */
class ImageFileProcessor extends AbstractFileProcessor
{
    public function __construct(
        protected VisionServiceInterface $visionService,
        protected AiPreset $presetModel,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    /** @inheritDoc */
    public function supportedMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    }

    /** @inheritDoc */
    protected function extractText(File $file, string $absolutePath): array
    {
        $preset = $this->presetModel->find($file->preset_id);

        if (!$preset) {
            throw new \RuntimeException('Preset not found for this image file.');
        }

        if (!$this->visionService->isAvailable($preset)) {
            throw new \RuntimeException(
                'Vision capability is not configured/active for this preset — image cannot be described. '
                . 'Configure Vision in Capabilities for this preset and re-process the file.'
            );
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Could not read the image file from storage.');
        }

        $image = ImageData::fromBinary(
            bytes:       $bytes,
            mimeType:    $file->mime_type ?: 'image/jpeg',
            sourceLabel: "document:{$file->id}",
        );

        // Structured result so the failure reason reaches the user.
        $result = $this->visionService->describeResult($image, null, $preset);

        if (!$result->success) {
            // Bubbles up to markFailed() with this exact reason.
            throw new \RuntimeException($result->error ?? 'Vision returned no description.');
        }

        $text = "[Image: {$file->original_name}]\n" . trim($result->text);

        return [
            'text' => $text,
            'meta' => [
                'described_by_vision' => true,
                'description_chars'   => mb_strlen($result->text),
            ],
        ];
    }
}
