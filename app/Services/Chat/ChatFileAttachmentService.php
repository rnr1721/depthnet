<?php

namespace App\Services\Chat;

use App\Contracts\Chat\ChatFileAttachmentServiceInterface;
use App\Models\AiPreset;
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
