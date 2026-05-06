<?php

namespace App\Contracts\Agent\FileStorage;

use App\Models\File;

/**
 * Contract for file content processors.
 *
 * Each processor knows how to extract text from a specific set of MIME types,
 * split it into chunks, and return them for vector indexing.
 *
 * Register processors in FileProcessorRegistry via ServiceProvider.
 * Order of registration determines priority when multiple processors
 * claim the same MIME type — first match wins.
 */
interface FileProcessorInterface
{
    /**
     * Return MIME types this processor can handle.
     *
     * @return string[]  e.g. ['application/pdf', 'application/x-pdf']
     */
    public function supportedMimeTypes(): array;

    /**
     * Whether this processor can handle the given MIME type.
     *
     * @param string $mimeType
     * @return boolean
     */
    public function supports(string $mimeType): bool;

    /**
     * Extract text chunks from the file's physical content.
     *
     * The file's absolute path is resolved by FileStorageFactory before
     * this method is called — the processor only deals with content.
     *
     * @param  File    $file         Model with metadata
     * @param  string  $absolutePath Absolute path to the physical file
     * @return ProcessingResult
     */
    public function process(File $file, string $absolutePath): ProcessingResult;
}
