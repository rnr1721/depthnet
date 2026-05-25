<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\AggregatedRagResultInterface;
use App\Contracts\Agent\Enricher\Rag\RagContentFormatterInterface;
use App\Contracts\Agent\Enricher\Rag\RagDataInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionRendererRegistryInterface;
use App\Contracts\Agent\Enricher\Rag\RagSectionType;
use Psr\Log\LoggerInterface;

/**
 * Default RAG content formatter.
 *
 * Wraps the output in [RAG CONTEXT — query: ...] / [END RAG CONTEXT]
 * and dispatches each section to its type-specific renderer.
 *
 * Two modes share the same per-section rendering:
 *   - formatIndividual(RagData)        : one config's output, for UI logging
 *   - formatAggregated(AggregatedResult) : merged output, for [[rag_context]]
 *
 * The only difference between modes is the source of sections and queries.
 */
final class RagContentFormatter implements RagContentFormatterInterface
{
    public function __construct(
        private readonly RagSectionRendererRegistryInterface $rendererRegistry,
        private readonly LoggerInterface                     $logger,
    ) {
    }

    public function formatIndividual(RagDataInterface $data): string
    {
        if ($data->isEmpty()) {
            return '';
        }

        return $this->renderBlock(
            queries:  $data->getQueries(),
            sections: $data->getSections(),
        );
    }

    public function formatAggregated(AggregatedRagResultInterface $result): string
    {
        if ($result->isEmpty()) {
            return '';
        }

        return $this->renderBlock(
            queries:  $result->getQueries(),
            sections: $result->getSections(),
        );
    }

    /**
     * @param string[]              $queries
     * @param RagSectionInterface[] $sections
     */
    private function renderBlock(array $queries, array $sections): string
    {
        $orderedSections = $this->orderSections($sections);

        $lines = [$this->renderHeader($queries), ''];

        foreach ($orderedSections as $section) {
            if ($section->isEmpty()) {
                continue;
            }

            $renderedLines = $this->renderSection($section);

            if (empty($renderedLines)) {
                continue;
            }

            $lines[] = $this->resolveLabel($section);
            $lines   = array_merge($lines, $renderedLines);
            $lines[] = '';
        }

        $lines[] = '[END RAG CONTEXT]';

        return implode("\n", $lines);
    }

    /**
     * @param string[] $queries
     */
    private function renderHeader(array $queries): string
    {
        if (empty($queries)) {
            return '[RAG CONTEXT]';
        }

        $queryHeader = count($queries) === 1
            ? sprintf('query: "%s"', $queries[0])
            : 'queries: ' . implode(' | ', array_map(fn ($q) => sprintf('"%s"', $q), $queries));

        return "[RAG CONTEXT — {$queryHeader}]";
    }

    private function resolveLabel(RagSectionInterface $section): string
    {
        $explicit = $section->getLabel();

        if ($explicit !== null) {
            return $explicit;
        }

        if (!$this->rendererRegistry->has($section->getType())) {
            return '[' . strtoupper($section->getType()) . ']';
        }

        return $this->rendererRegistry->get($section->getType())->defaultLabel();
    }

    /**
     * @return string[]
     */
    private function renderSection(RagSectionInterface $section): array
    {
        try {
            return $this->rendererRegistry->get($section->getType())->render($section);
        } catch (\OutOfBoundsException $e) {
            $this->logger->warning('RAG: no renderer for section type', [
                'type' => $section->getType(),
            ]);
            return [];
        } catch (\Throwable $e) {
            $this->logger->error('RAG: section render failed', [
                'type'  => $section->getType(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Order sections by canonical render order.
     *
     * @param  RagSectionInterface[] $sections
     * @return RagSectionInterface[]
     */
    private function orderSections(array $sections): array
    {
        usort($sections, function (RagSectionInterface $a, RagSectionInterface $b) {
            return $this->renderOrderFor($a->getType()) <=> $this->renderOrderFor($b->getType());
        });

        return $sections;
    }

    /**
     * Resolve render order, falling back to a high value for unknown types
     * so they appear at the end.
     */
    private function renderOrderFor(string $type): int
    {
        $enum = RagSectionType::tryFrom($type);
        return $enum?->renderOrder() ?? 9999;
    }
}
