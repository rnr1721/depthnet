<?php

namespace App\Services\Agent\FileStorage\Processors;

use App\Contracts\Agent\FileStorage\ProcessingResult;
use App\Models\File;

/**
 * Fallback processor for unrecognised MIME types.
 *
 * Attempts to read the file as plain text. If the content is not
 * valid text (binary), stores the file with zero chunks so it is
 * still registered in the database and accessible to the agent
 * via TerminalPlugin in sandbox mode.
 *
 * Always register this processor last in FileProcessorRegistry.
 */
class FallbackFileProcessor extends AbstractFileProcessor
{
    /** @inheritDoc */
    public function supportedMimeTypes(): array
    {
        return ['*'];
    }

    /** @inheritDoc */
    public function supports(string $mimeType): bool
    {
        return true; // catches everything
    }

    /** @inheritDoc */
    protected function extractText(File $file, string $absolutePath): array
    {
        $content = @file_get_contents($absolutePath);

        if ($content === false) {
            return ['text' => '', 'meta' => ['fallback' => true, 'readable' => false]];
        }

        // Heuristic: if >30% of the first 512 bytes are non-printable, treat as binary
        $sample     = substr($content, 0, 512);
        $nonPrint   = preg_match_all('/[^\x09\x0A\x0D\x20-\x7E]/', $sample);
        $isBinary   = strlen($sample) > 0 && ($nonPrint / strlen($sample)) > 0.30;

        if ($isBinary) {
            return [
                'text' => '',
                'meta' => ['fallback' => true, 'binary' => true, 'readable' => false],
            ];
        }

        return [
            'text' => $content,
            'meta' => ['fallback' => true, 'binary' => false, 'readable' => true],
        ];
    }
}
