<?php

namespace App\Services\Agent\FileStorage\Processors;

use App\Contracts\Agent\FileStorage\FileProcessorInterface;
use App\Contracts\Agent\FileStorage\ProcessingResult;
use App\Models\File;
use Psr\Log\LoggerInterface;

/**
 * Base class for file processors.
 *
 * Provides shared text chunking logic so concrete processors
 * only need to implement text extraction.
 */
abstract class AbstractFileProcessor implements FileProcessorInterface
{
    /**
     * Default chunk size in characters.
     * Roughly ~300-400 tokens — fits well in embedding models.
     */
    protected int $defaultChunkSize = 1500;

    /**
     * Overlap between adjacent chunks in characters.
     * Prevents losing context at chunk boundaries.
     */
    protected int $defaultOverlap = 150;

    public function __construct(
        protected LoggerInterface $logger,
    ) {
    }

    /** @inheritDoc */
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, $this->supportedMimeTypes(), true);
    }

    /**
     * Concrete processors implement this to extract raw text from the file.
     *
     * @return array{text: string, meta: array}
     */
    abstract protected function extractText(File $file, string $absolutePath): array;

    /** @inheritDoc */
    public function process(File $file, string $absolutePath): ProcessingResult
    {
        try {
            ['text' => $text, 'meta' => $meta] = $this->extractText($file, $absolutePath);

            if (empty(trim($text))) {
                return ProcessingResult::ok(chunks: [], meta: array_merge($meta, ['empty' => true]));
            }

            $chunks = $this->chunkText($text, $this->defaultChunkSize, $this->defaultOverlap);

            return ProcessingResult::ok(
                chunks: $chunks,
                meta: array_merge($meta, ['chunk_count' => count($chunks)]),
            );

        } catch (\Throwable $e) {
            $this->logger->error(static::class . '::process failed', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);

            return ProcessingResult::fail($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Chunking helpers
    // -------------------------------------------------------------------------

    /**
     * Split text into overlapping chunks by character count,
     * respecting paragraph and sentence boundaries where possible.
     *
     * @return string[]
     */
    protected function chunkText(string $text, int $chunkSize, int $overlap): array
    {
        $text = $this->normalizeWhitespace($text);

        if (mb_strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks   = [];
        $length   = mb_strlen($text);
        $start    = 0;

        while ($start < $length) {
            $end = min($start + $chunkSize, $length);

            // Try to break at paragraph boundary
            if ($end < $length) {
                $slice      = mb_substr($text, $start, $end - $start);
                $breakPoint = $this->findBreakPoint($slice);
                if ($breakPoint !== null) {
                    $end = $start + $breakPoint;
                }
            }

            $chunk = trim(mb_substr($text, $start, $end - $start));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            // Advance with overlap
            $start = max($start + 1, $end - $overlap);
        }

        return $chunks;
    }

    /**
     * Split text into chunks by logical units (paragraphs / lines),
     * grouping them so each chunk does not exceed $maxChunkSize.
     * Useful for structured text (code, CSV, spreadsheets).
     *
     * @param  string[]  $units
     * @return string[]
     */
    protected function chunkByUnits(array $units, int $maxChunkSize): array
    {
        $chunks  = [];
        $current = '';

        foreach ($units as $unit) {
            $unit = trim($unit);
            if ($unit === '') {
                continue;
            }

            if ($current === '') {
                $current = $unit;
                continue;
            }

            $candidate = $current . "\n\n" . $unit;

            if (mb_strlen($candidate) > $maxChunkSize) {
                $chunks[]  = $current;
                $current   = $unit;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find the best break point (paragraph > sentence > word) within a slice.
     * Returns character offset or null if no good break was found.
     */
    private function findBreakPoint(string $slice): ?int
    {
        // Prefer paragraph break
        $pos = mb_strrpos($slice, "\n\n");
        if ($pos !== false && $pos > mb_strlen($slice) * 0.5) {
            return $pos + 2;
        }

        // Sentence boundary
        foreach (['. ', '! ', '? ', ".\n"] as $delim) {
            $pos = mb_strrpos($slice, $delim);
            if ($pos !== false && $pos > mb_strlen($slice) * 0.6) {
                return $pos + mb_strlen($delim);
            }
        }

        // Word boundary
        $pos = mb_strrpos($slice, ' ');
        if ($pos !== false && $pos > mb_strlen($slice) * 0.7) {
            return $pos + 1;
        }

        return null;
    }

    private function normalizeWhitespace(string $text): string
    {
        // Collapse 3+ blank lines into 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        // Collapse multiple spaces/tabs on a single line
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        return trim($text);
    }
}
