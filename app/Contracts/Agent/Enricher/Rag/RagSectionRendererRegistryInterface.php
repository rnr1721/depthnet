<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * Look up the renderer for a given section type.
 *
 * The registry is populated via DI in the service provider. Adding a new
 * section type means: add a case to RagSectionType, implement
 * RagSectionRendererInterface, register the renderer here.
 * Nothing else changes.
 */
interface RagSectionRendererRegistryInterface
{
    /**
     * Get the renderer for a section type.
     *
     * @throws \OutOfBoundsException when no renderer is registered for the type
     */
    public function get(string $sectionType): RagSectionRendererInterface;

    /**
     * True when a renderer is registered for the given type.
     */
    public function has(string $sectionType): bool;

    /**
     * @return RagSectionRendererInterface[]
     */
    public function all(): array;
}
