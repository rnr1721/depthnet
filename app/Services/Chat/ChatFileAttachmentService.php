<?php

namespace App\Services\Chat;

use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Contracts\Chat\ChatFileAttachmentServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\FileStorage\FileService;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;

/**
 * Handles file attachments submitted together with a chat message.
 *
 * Responsibilities:
 *   - Store each uploaded file via FileService
 *   - Build a mode-aware hint appended to the message content
 *
 * Mode-aware hint:
 *   In tag mode    → [documents search]query[/documents]
 *   In tool_calls  → neutral text (model reads getToolSchema instructions)
 *   Detection via  → $preset->getAgentResultMode()
 */
class ChatFileAttachmentService implements ChatFileAttachmentServiceInterface
{
    public function __construct(
        protected FileService   $fileService,
        protected VisionServiceInterface $visionService,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Process uploaded files and return annotation + file metadata.
     *
     * @param  UploadedFile[]  $uploads
     * @return array{annotation: string|null, file_ids: int[], files: array}
     */
    public function process(array $uploads, AiPreset $preset): array
    {
        if (empty($uploads)) {
            return ['annotation' => null, 'file_ids' => [], 'files' => []];
        }

        $notes   = [];
        $fileIds = [];
        $files   = [];

        foreach ($uploads as $upload) {
            if (!($upload instanceof UploadedFile) || !$upload->isValid()) {
                continue;
            }

            try {
                $file = $this->fileService->store(
                    upload:  $upload,
                    preset:  $preset,
                    driver:  'laravel',
                    scope:   'private',
                );

                $chunkInfo = $file->is_processed
                    ? "{$file->chunk_count} chunks indexed"
                    : 'processing pending';

                $notes[]   = "• {$file->original_name} (file_id:{$file->id}, {$chunkInfo}, {$file->human_size})";
                $fileIds[] = $file->id;
                $files[]   = [
                    'id'            => $file->id,
                    'original_name' => $file->original_name,
                    'human_size'    => $file->human_size,
                    'mime_type'     => $file->mime_type,
                ];

            } catch (\Throwable $e) {
                $this->logger->warning('ChatFileAttachmentService: failed to store file', [
                    'preset_id' => $preset->id,
                    'filename'  => $upload->getClientOriginalName(),
                    'error'     => $e->getMessage(),
                ]);
                $notes[] = "• {$upload->getClientOriginalName()} [upload failed: {$e->getMessage()}]";
            }
        }

        if (empty($notes)) {
            return ['annotation' => null, 'file_ids' => [], 'files' => []];
        }

        $hint = $this->buildHint($preset);

        $annotation = "\n\n[Attached files:\n"
            . implode("\n", $notes) . "\n"
            . $hint . ']';

        return [
            'annotation' => $annotation,
            'file_ids'   => $fileIds,
            'files'      => $files,
        ];
    }

    /**
     * Describe images for the "show in chat" path — runs vision WITHOUT storing
     * the files (no documents, no chunks, no embedding). Returns an annotation
     * block of ```photo``` markers to append to the message content, plus light
     * metadata for the frontend.
     *
     * Used when attach_mode = 'chat'. Non-image files should NOT be passed here —
     * the controller routes them to process() instead.
     *
     * @param  UploadedFile[]  $imageUploads
     * @return array{annotation: string|null, photos: array}
     */
    public function describeForChat(array $imageUploads, AiPreset $preset): array
    {
        if (empty($imageUploads)) {
            return ['annotation' => null, 'photos' => []];
        }

        if (!$this->visionService->isAvailable($preset)) {
            // No vision — tell the user plainly instead of silently dropping the image.
            $names = implode(', ', array_map(
                fn ($u) => $u instanceof UploadedFile ? $u->getClientOriginalName() : 'image',
                $imageUploads
            ));
            return [
                'annotation' => "\n\n```photo\n[Vision is not configured for this preset — "
                    . "attached image(s) could not be described: {$names}]\n```",
                'photos' => [],
            ];
        }

        $blocks = [];
        $photos = [];

        foreach ($imageUploads as $upload) {
            if (!($upload instanceof UploadedFile) || !$upload->isValid()) {
                continue;
            }

            $name = $upload->getClientOriginalName();

            try {
                $bytes = @file_get_contents($upload->getRealPath());
                if ($bytes === false || $bytes === '') {
                    $blocks[] = "```photo\n[{$name}: could not read image]\n```";
                    continue;
                }

                $image = ImageData::fromBinary(
                    bytes:       $bytes,
                    mimeType:    $upload->getMimeType() ?: 'image/jpeg',
                    sourceLabel: "chat:{$name}",
                );

                $result = $this->visionService->describeResult($image, null, $preset);

                if ($result->success) {
                    // Marker block — frontend cuts it out and renders a chip;
                    // the model reads the description as plain text.
                    $blocks[] = "```photo\n[{$name}]\n{$result->text}\n```";
                    $photos[] = ['original_name' => $name, 'described' => true];
                } else {
                    $blocks[] = "```photo\n[{$name}: vision failed — {$result->error}]\n```";
                    $photos[] = ['original_name' => $name, 'described' => false, 'error' => $result->error];
                }

            } catch (\Throwable $e) {
                $this->logger->warning('ChatFileAttachmentService: describeForChat failed', [
                    'preset_id' => $preset->id,
                    'filename'  => $name,
                    'error'     => $e->getMessage(),
                ]);
                $blocks[] = "```photo\n[{$name}: error — {$e->getMessage()}]\n```";
            }
        }

        if (empty($blocks)) {
            return ['annotation' => null, 'photos' => []];
        }

        return [
            'annotation' => "\n\n" . implode("\n", $blocks),
            'photos'     => $photos,
        ];
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Build a mode-appropriate usage hint for the agent.
     */
    private function buildHint(AiPreset $preset): string
    {
        if ($preset->getAgentResultMode() === 'tool_calls') {
            // In tool_calls mode the model reads getToolSchema() — no tags needed.
            return 'Use the documents tool (method: search) to find relevant content, '
                . 'or (method: list) to see all available files.';
        }

        // Tag mode — explicit syntax hint.
        return 'Use [documents search]your query[/documents] to search file contents, '
            . 'or [documents list][/documents] to see all files.';
    }
}
