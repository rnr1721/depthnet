<?php

namespace App\Services\Agent\FileStorage\Processors;

use App\Models\File;

/**
 * Processor for plain text and source code files.
 *
 * Text files are read as-is and chunked by paragraph / sentence boundaries.
 * Code files are chunked by logical units (functions, classes) when possible,
 * falling back to line-group chunking.
 */
class PlainTextFileProcessor extends AbstractFileProcessor
{
    /** @inheritDoc */
    public function supportedMimeTypes(): array
    {
        return [
            'text/plain',
            'text/markdown',
            'text/x-markdown',
            'text/html',
            'text/xml',
            'application/xml',
            'application/json',
            'text/csv',
            // Common code MIME types
            'text/x-php',
            'application/x-php',
            'text/x-python',
            'text/x-java',
            'text/javascript',
            'application/javascript',
            'text/typescript',
            'text/x-c',
            'text/x-c++',
            'text/x-shellscript',
        ];
    }

    /** @inheritDoc */
    protected function extractText(File $file, string $absolutePath): array
    {
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$absolutePath}");
        }

        // Ensure valid UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
            $content  = $detected
                ? mb_convert_encoding($content, 'UTF-8', $detected)
                : mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        $lineCount = substr_count($content, "\n") + 1;
        $encoding  = mb_detect_encoding($content) ?: 'UTF-8';

        return [
            'text' => $content,
            'meta' => [
                'line_count' => $lineCount,
                'encoding'   => $encoding,
                'size_chars' => mb_strlen($content),
            ],
        ];
    }
}
