<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * A typed group of RAG items, rendered together as a labeled block.
 *
 * Sections preserve semantics that flat chunk lists would lose:
 *   - Different memory modes (associative/keyword/flat) remain visually distinct
 *   - Journal anchors keep their relationship to neighbours
 *   - Ontology snapshots stay as coherent multi-line blocks
 *
 * The section's type determines which renderer the formatter uses.
 * Render options carry per-section parameters (relative date display,
 * journal context window, etc.) that affect presentation.
 */
interface RagSectionInterface
{
    /**
     * Section type identifier — one of RagSectionType values.
     *
     * Determines which renderer the formatter dispatches to.
     */
    public function getType(): string;

    /**
     * Optional override for the section's display label.
     *
     * When null, the renderer uses its default label for the type
     * (e.g. "[SEMANTIC ASSOCIATIVE MEMORY]").
     */
    public function getLabel(): ?string;

    /**
     * @return RagItemInterface[]
     */
    public function getItems(): array;

    /**
     * Per-section render parameters.
     *
     * Used by renderers to customize output without changing structure.
     * Common options:
     *   - 'show_relative_date' => bool
     *   - 'journal_context_window' => int
     *   - 'max_content_limit' => int
     *
     * @return array<string, mixed>
     */
    public function getRenderOptions(): array;

    /**
     * True when the section carries no items.
     */
    public function isEmpty(): bool;
}
