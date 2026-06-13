<?php

namespace App\Services\Agent\FileStorage\Processors;

use App\Models\File;
use Smalot\PdfParser\Parser;

/**
 * Processor for PDF files.
 *
 * Extracts text from all pages using smalot/pdfparser.
 * Each page becomes a natural chunk boundary — pages are grouped
 * into chunks not exceeding defaultChunkSize to avoid oversized blocks
 * from dense PDFs.
 *
 * Requires: composer require smalot/pdfparser
 *
 * Falls back to FallbackFileProcessor behaviour (empty chunks, binary flag)
 * when the PDF contains no extractable text (scanned images, encrypted).
 */
class PdfFileProcessor extends AbstractFileProcessor
{
    /** @inheritDoc */
    public function supportedMimeTypes(): array
    {
        return [
            'application/pdf',
            'application/x-pdf',
        ];
    }

    /** @inheritDoc */
    protected function extractText(File $file, string $absolutePath): array
    {
        if (!class_exists(Parser::class)) {
            throw new \RuntimeException(
                'smalot/pdfparser is not installed. Run: composer require smalot/pdfparser'
            );
        }

        $parser   = new Parser();
        $pdf      = $parser->parseFile($absolutePath);
        $pages    = $pdf->getPages();
        $pageCount = count($pages);

        if ($pageCount === 0) {
            return ['text' => '', 'meta' => ['page_count' => 0, 'empty' => true]];
        }

        // Extract text per page, then chunk by page groups
        $pageTexts = [];
        $totalChars = 0;

        foreach ($pages as $i => $page) {
            try {
                $text = $page->getText();
                if (trim($text) !== '') {
                    $pageTexts[$i + 1] = $text; // 1-based page number
                    $totalChars += mb_strlen($text);
                }
            } catch (\Throwable) {
                // Some pages may fail (e.g. encrypted, image-only) — skip them
            }
        }

        if (empty($pageTexts)) {
            return [
                'text' => '',
                'meta' => [
                    'page_count'    => $pageCount,
                    'readable_pages' => 0,
                    'binary'        => true,
                    'note'          => 'PDF contains no extractable text (scanned or encrypted)',
                ],
            ];
        }

        // Join pages with a separator so chunkText() can split on paragraph boundaries
        // Include page numbers so the agent can reference them
        $parts = [];
        foreach ($pageTexts as $pageNum => $text) {
            $parts[] = "[Page {$pageNum}]\n" . trim($text);
        }

        $fullText = implode("\n\n", $parts);

        return [
            'text' => $fullText,
            'meta' => [
                'page_count'     => $pageCount,
                'readable_pages' => count($pageTexts),
                'total_chars'    => $totalChars,
            ],
        ];
    }
}
