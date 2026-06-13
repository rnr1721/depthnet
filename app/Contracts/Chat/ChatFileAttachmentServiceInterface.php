<?php

namespace App\Contracts\Chat;

use App\Models\AiPreset;
use Illuminate\Http\UploadedFile;

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
interface ChatFileAttachmentServiceInterface
{
    /**
     * Process uploaded files and return annotation + file metadata.
     *
     * @param  UploadedFile[]  $uploads
     * @param AiPreset $preset
     * @return array{annotation: string|null, file_ids: int[], files: array}
     */
    public function process(array $uploads, AiPreset $preset): array;

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
    public function describeForChat(array $imageUploads, AiPreset $preset): array;
}
