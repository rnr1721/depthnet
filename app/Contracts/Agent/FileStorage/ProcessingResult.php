<?php

namespace App\Contracts\Agent\FileStorage;

/**
 * Result returned by a FileProcessor after extracting content from a file.
 *
 * chunks — ordered list of text fragments ready for TF-IDF / embedding indexing.
 * meta   — processor-specific metadata to merge into files.meta
 *          (e.g. page_count, sheet_names, encoding, language, line_count).
 */
final class ProcessingResult
{
    /**
     * @param  string[]  $chunks  Ordered text fragments extracted from the file
     * @param  array     $meta    Extra metadata about the file content
     * @param  bool      $success Whether processing succeeded
     * @param  string|null $error  Error message if processing failed
     */
    public function __construct(
        public readonly array $chunks,
        public readonly array $meta = [],
        public readonly bool $success = true,
        public readonly ?string $error = null,
    ) {
    }

    public static function ok(array $chunks, array $meta = []): self
    {
        return new self(chunks: $chunks, meta: $meta, success: true);
    }

    public static function fail(string $error): self
    {
        return new self(chunks: [], meta: ['error' => $error], success: false, error: $error);
    }
}
