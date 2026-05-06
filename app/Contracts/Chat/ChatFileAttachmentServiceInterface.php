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
}
