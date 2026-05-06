<?php

namespace App\Services\Agent\FileStorage;

use App\Contracts\Agent\FileStorage\FileProcessorInterface;

/**
 * Registry of available file processors.
 *
 * Processors are checked in registration order — first match wins.
 * Register via ServiceProvider:
 *
 *   $registry = $app->make(FileProcessorRegistry::class);
 *   $registry->register($app->make(PdfFileProcessor::class));
 *   $registry->register($app->make(PlainTextFileProcessor::class));
 *   $registry->register($app->make(SpreadsheetFileProcessor::class));
 *   $registry->register($app->make(FallbackFileProcessor::class));
 */
class FileProcessorRegistry
{
    /** @var FileProcessorInterface[] */
    private array $processors = [];

    public function register(FileProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }

    /**
     * Find the first processor that supports the given MIME type.
     */
    public function resolve(string $mimeType): ?FileProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($mimeType)) {
                return $processor;
            }
        }

        return null;
    }

    /**
     * @return FileProcessorInterface[]
     */
    public function all(): array
    {
        return $this->processors;
    }
}
