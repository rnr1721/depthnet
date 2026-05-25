<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagItemInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;

/**
 * Default implementation of RagSectionInterface.
 *
 * Immutable, but exposes withItems() for cases where the aggregator
 * needs to produce a merged section without mutating the original.
 */
final class RagSection implements RagSectionInterface
{
    /**
     * @param RagItemInterface[]   $items
     * @param array<string, mixed> $renderOptions
     */
    public function __construct(
        private readonly string  $type,
        private readonly array   $items,
        private readonly ?string $label = null,
        private readonly array   $renderOptions = [],
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getRenderOptions(): array
    {
        return $this->renderOptions;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Return a new section with replaced items, preserving type, label, options.
     *
     * Used by the aggregator when merging sections of the same type.
     *
     * @param RagItemInterface[] $items
     */
    public function withItems(array $items): self
    {
        return new self(
            type:          $this->type,
            items:         $items,
            label:         $this->label,
            renderOptions: $this->renderOptions,
        );
    }
}
