<?php

declare(strict_types=1);

namespace App\Services\Sandbox\DTO;

/**
 * Result of code execution with language-specific details
 */
class CodeExecutionResult extends ExecutionResult
{
    public function __construct(
        string $output,
        string $error,
        int $exitCode,
        float $executionTime,
        bool $timedOut,
        public readonly string $language,
        public readonly string $sandboxId,
        public readonly array $files = [],
        public readonly array $metadata = []
    ) {
        parent::__construct($output, $error, $exitCode, $executionTime, $timedOut);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'language' => $this->language,
            'sandbox_id' => $this->sandboxId,
            'files' => $this->files,
            'metadata' => $this->metadata,
        ]);
    }
}
