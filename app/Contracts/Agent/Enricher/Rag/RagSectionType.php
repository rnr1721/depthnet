<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * Canonical section types for RAG output.
 *
 * Each type corresponds to a registered RagSectionRenderer in the
 * RagSectionRendererRegistry. Adding a new type means:
 *   1. Add a case here
 *   2. Implement RagSectionRendererInterface
 *   3. Register it in the registry
 *
 * Memory has multiple variants because the original semantics
 * (associative vs keyword vs flat) carry meaning for the model —
 * the model treats "[SEMANTIC ASSOCIATIVE MEMORY]" differently
 * from "[KEYWORD MEMORY — no embedding yet]".
 */
enum RagSectionType: string
{
    case MemorySemanticAssociative = 'memory_semantic_associative';
    case MemorySemantic            = 'memory_semantic';
    case MemoryAssociative         = 'memory_associative';
    case MemoryKeyword             = 'memory_keyword';
    case MemoryKeywordFallback     = 'memory_keyword_fallback';
    case MemoryAdditional          = 'memory_additional';
    case Journal                   = 'journal';
    case Ontology                  = 'ontology';
    case Persons                   = 'persons';
    case Skills                    = 'skills';
    case Files                     = 'files';

    /**
     * Render order for the formatter.
     *
     * Returns the canonical sort key — lower comes first.
     */
    public function renderOrder(): int
    {
        return match ($this) {
            self::MemorySemanticAssociative => 10,
            self::MemorySemantic            => 20,
            self::MemoryAssociative         => 30,
            self::MemoryKeyword             => 40,
            self::MemoryKeywordFallback     => 50,
            self::MemoryAdditional          => 60,
            self::Skills                    => 70,
            self::Journal                   => 80,
            self::Ontology                  => 90,
            self::Files                     => 100,
            self::Persons                   => 110,
        };
    }
}
