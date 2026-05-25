<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagSectionRendererInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionRendererRegistryInterface;

/**
 * Default registry — accepts renderers via constructor injection.
 *
 * Service provider wires this up by passing the list of registered
 * renderer implementations. Each renderer declares its supported type
 * via supports(), so registration is self-describing.
 */
final class RagSectionRendererRegistry implements RagSectionRendererRegistryInterface
{
    /** @var array<string, RagSectionRendererInterface> */
    private array $renderers = [];

    /**
     * @param iterable<RagSectionRendererInterface> $renderers
     */
    public function __construct(iterable $renderers = [])
    {
        foreach ($renderers as $renderer) {
            $this->register($renderer);
        }
    }

    public function register(RagSectionRendererInterface $renderer): void
    {
        $this->renderers[$renderer->supports()] = $renderer;
    }

    public function get(string $sectionType): RagSectionRendererInterface
    {
        if (!isset($this->renderers[$sectionType])) {
            throw new \OutOfBoundsException(
                "No RAG section renderer registered for type '{$sectionType}'"
            );
        }

        return $this->renderers[$sectionType];
    }

    public function has(string $sectionType): bool
    {
        return isset($this->renderers[$sectionType]);
    }

    public function all(): array
    {
        return array_values($this->renderers);
    }
}
