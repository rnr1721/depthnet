<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagItemInterface;

/**
 * Default implementation of RagItemInterface.
 *
 * Immutable value object — once constructed, a chunk is just data.
 */
final class RagItem implements RagItemInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $dedupKey,
        private readonly string $content,
        private readonly ?float $score = null,
        private readonly array  $metadata = [],
    ) {
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
