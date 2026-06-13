<?php

namespace App\Services\Chat;

use App\Contracts\Agent\Capabilities\VisionServiceInterface;
use App\Contracts\Chat\ChatFileAttachmentServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Vision\DTO\ImageData;
use App\Services\Agent\FileStorage\FileProcessorRegistry;
use App\Services\Agent\FileStorage\FileService;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;

/**
 * Handles file attachments submitted with a chat message.
 *
 * Two symmetric modes, consistent for images AND documents:
 *
 *   "show"      — resolve content to text and place it in the message (truncated
 *                 to a visible limit), WITHOUT storing. Show-and-forget.
 *   "documents" — store + index for RAG, AND show the resolved content inline
 *                 (same truncated preview). Crystallize and show.
 *
 * Images resolve via the vision capability; everything else via the file
 * processor's extractRaw(). The marker is ```photo``` for images and ```file```
 * for documents — the frontend renders both as chips.
 *
 * The visible limit applies ONLY to what goes into the message. In documents
 * mode the file is stored/chunked in full; only the inline preview is truncated.
 */
class ChatFileAttachmentService implements ChatFileAttachmentServiceInterface
{
    /**
     * Default max characters of resolved file/text content shown inline in the
     * message. The full file still goes to RAG in documents mode. Intended to be
     * overridden per-preset in the future (see resolveVisibleLimit()).
     */
    private const DEFAULT_VISIBLE_LIMIT = 6000;

    public function __construct(
        protected FileService $fileService,
        protected FileProcessorRegistry $processorRegistry,
        protected VisionServiceInterface $visionService,
        protected LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Documents mode — store for RAG AND show resolved content inline
    // -------------------------------------------------------------------------

    /**
     * Store files for RAG and return an annotation that ALSO shows the resolved
     * content inline (truncated), so the model sees it immediately — not just a
     * "search the documents" hint.
     *
     * @param  UploadedFile[]  $uploads
     * @return array{annotation: string|null, file_ids: int[], files: array}
     */
    public function process(array $uploads, AiPreset $preset): array
    {
        if (empty($uploads)) {
            return ['annotation' => null, 'file_ids' => [], 'files' => []];
        }

        $limit   = $this->resolveVisibleLimit($preset);
        $notes   = [];
        $blocks  = [];   // inline content previews
        $fileIds = [];
        $files   = [];

        foreach ($uploads as $upload) {
            if (!($upload instanceof UploadedFile) || !$upload->isValid()) {
                continue;
            }

            $name = $upload->getClientOriginalName();

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

                // Also show the resolved content inline (truncated).
                $preview = $this->resolvePreview($file->mime_type, $file, $name, $limit);
                if ($preview !== null) {
                    $blocks[] = $preview;
                }

            } catch (\Throwable $e) {
                $this->logger->warning('ChatFileAttachmentService: failed to store file', [
                    'preset_id' => $preset->id,
                    'filename'  => $name,
                    'error'     => $e->getMessage(),
                ]);
                $notes[] = "• {$name} [upload failed: {$e->getMessage()}]";
            }
        }

        if (empty($notes)) {
            return ['annotation' => null, 'file_ids' => [], 'files' => []];
        }

        $hint = $this->buildHint($preset);

        $annotation = "\n\n[Attached files:\n"
            . implode("\n", $notes) . "\n"
            . $hint . ']';

        // Append inline content previews after the file list.
        if (!empty($blocks)) {
            $annotation .= "\n\n" . implode("\n", $blocks);
        }

        return [
            'annotation' => $annotation,
            'file_ids'   => $fileIds,
            'files'      => $files,
        ];
    }

    // -------------------------------------------------------------------------
    // Show mode — resolve content inline WITHOUT storing
    // -------------------------------------------------------------------------

    /**
     * Resolve images via vision and documents via extractRaw(), placing content
     * inline WITHOUT storing anything. Used when attach_mode = 'chat'.
     *
     * Accepts any uploads: images → vision (```photo```), others → extractRaw
     * (```file```). Truncated to the visible limit.
     *
     * @param  UploadedFile[]  $uploads
     * @return array{annotation: string|null, photos: array}
     */
    public function describeForChat(array $uploads, AiPreset $preset): array
    {
        if (empty($uploads)) {
            return ['annotation' => null, 'photos' => []];
        }

        $limit  = $this->resolveVisibleLimit($preset);
        $blocks = [];
        $photos = [];

        foreach ($uploads as $upload) {
            if (!($upload instanceof UploadedFile) || !$upload->isValid()) {
                continue;
            }

            $name = $upload->getClientOriginalName();
            $mime = $upload->getMimeType() ?: 'application/octet-stream';

            if (str_starts_with($mime, 'image/')) {
                $blocks[] = $this->showImage($upload, $name, $mime, $preset, $photos);
            } else {
                $blocks[] = $this->showDocument($upload, $name, $mime, $limit);
            }
        }

        $blocks = array_filter($blocks);
        if (empty($blocks)) {
            return ['annotation' => null, 'photos' => []];
        }

        return [
            'annotation' => "\n\n" . implode("\n", $blocks),
            'photos'     => $photos,
        ];
    }

    // -------------------------------------------------------------------------
    // Private — resolution helpers
    // -------------------------------------------------------------------------

    /**
     * Vision-resolve a transient image (no storage). Mutates $photos.
     */
    private function showImage(
        UploadedFile $upload,
        string $name,
        string $mime,
        AiPreset $preset,
        array &$photos,
    ): string {
        if (!$this->visionService->isAvailable($preset)) {
            return "```photo\n[Vision not configured — image not described: {$name}]\n```";
        }

        try {
            $bytes = @file_get_contents($upload->getRealPath());
            if ($bytes === false || $bytes === '') {
                return "```photo\n[{$name}: could not read image]\n```";
            }

            $image  = ImageData::fromBinary($bytes, $mime ?: 'image/jpeg', "chat:{$name}");
            $result = $this->visionService->describeResult($image, null, $preset);

            if ($result->success) {
                $photos[] = ['original_name' => $name, 'described' => true];
                return "```photo\n[{$name}]\n{$result->text}\n```";
            }

            $photos[] = ['original_name' => $name, 'described' => false, 'error' => $result->error];
            return "```photo\n[{$name}: vision failed — {$result->error}]\n```";

        } catch (\Throwable $e) {
            $this->logger->warning('ChatFileAttachmentService: showImage failed', [
                'preset_id' => $preset->id, 'filename' => $name, 'error' => $e->getMessage(),
            ]);
            return "```photo\n[{$name}: error — {$e->getMessage()}]\n```";
        }
    }

    /**
     * extractRaw a transient document (no storage), truncated to $limit.
     */
    private function showDocument(UploadedFile $upload, string $name, string $mime, int $limit): string
    {
        $processor = $this->processorRegistry->resolve($mime);
        if (!$processor) {
            return "```file\n[{$name}: no processor for {$mime}]\n```";
        }

        try {
            ['text' => $text] = $processor->extractRaw(null, $upload->getRealPath());
            return $this->wrapFileBlock($name, $text, $limit);
        } catch (\Throwable $e) {
            $this->logger->warning('ChatFileAttachmentService: showDocument failed', [
                'filename' => $name, 'error' => $e->getMessage(),
            ]);
            return "```file\n[{$name}: could not read — {$e->getMessage()}]\n```";
        }
    }

    /**
     * Build an inline preview block for an already-stored file (documents mode).
     * Returns null if there's nothing to show.
     */
    private function resolvePreview(string $mime, \App\Models\File $file, string $name, int $limit): ?string
    {
        // Images stored as documents are described by ImageFileProcessor during
        // storage; their description is in chunks. For inline preview we re-resolve
        // via vision for a clean, untruncated-by-chunking description.
        if (str_starts_with($mime, 'image/')) {
            // Skip — image-in-documents already shows via its own pipeline; avoid a
            // second vision call here. (If you want a chip, handle via metadata.photos.)
            return null;
        }

        $processor = $this->processorRegistry->resolve($mime);
        if (!$processor) {
            return null;
        }

        try {
            $absPath = $this->fileService->getDownloadPath($file);
            ['text' => $text] = $processor->extractRaw($file, $absPath);
            return $this->wrapFileBlock($name, $text, $limit);
        } catch (\Throwable $e) {
            $this->logger->debug('ChatFileAttachmentService: preview failed', [
                'file_id' => $file->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Wrap document text in a ```file``` marker, truncated to $limit.
     */
    private function wrapFileBlock(string $name, string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '') {
            return "```file\n[{$name}: empty or no extractable text]\n```";
        }

        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit)
                . "\n\n…[truncated — full file available via documents search]";
        }

        return "```file\n[{$name}]\n{$text}\n```";
    }

    /**
     * Resolve the inline visible content limit for a preset.
     * Currently a constant; intended to read a per-preset setting later.
     */
    private function resolveVisibleLimit(AiPreset $preset): int
    {
        // Future: return (int) ($preset->getAttachmentVisibleLimit() ?? self::DEFAULT_VISIBLE_LIMIT);
        return self::DEFAULT_VISIBLE_LIMIT;
    }

    /**
     * Build a mode-appropriate usage hint for the agent.
     */
    private function buildHint(AiPreset $preset): string
    {
        if ($preset->getAgentResultMode() === 'tool_calls') {
            return 'Use the documents tool (method: search) to find relevant content, '
                . 'or (method: list) to see all available files.';
        }

        return 'Use [documents search]your query[/documents] to search file contents, '
            . 'or [documents list][/documents] to see all files.';
    }
}
