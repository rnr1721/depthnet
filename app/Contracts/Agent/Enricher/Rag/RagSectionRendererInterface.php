<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * Renders one section type into lines of text.
 *
 * Each section type has its own renderer implementation registered
 * in RagSectionRendererRegistry. Renderers are pure: they take a section
 * and return strings, without side effects.
 */
interface RagSectionRendererInterface
{
    /**
     * Which section type this renderer handles — value from RagSectionType.
     */
    public function supports(): string;

    /**
     * Default label used when section->getLabel() returns null.
     *
     * Example: "[SEMANTIC ASSOCIATIVE MEMORY]".
     */
    public function defaultLabel(): string;

    /**
     * Render the section body to text lines.
     *
     * The label line is prepended by the formatter, not by the renderer.
     * Returned strings do not include trailing newlines — the formatter
     * joins them with "\n".
     *
     * @return string[]
     */
    public function render(RagSectionInterface $section): array;
}
