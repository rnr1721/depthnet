<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagDataInterface;

/**
 * A cacheable snapshot of a RAG assembly pass.
 *
 * Carries everything needed to (a) resume retrieval deduplication in a later
 * synchronous pass and (b) merge warm + fresh payloads into the exact text a
 * single synchronous pass would have produced.
 *
 * Serializable by construction: payloads are dehydrated RagData (no Eloquent
 * models in item metadata — see RagContextEnricher::map*Items), seenIds is a
 * flat string set.
 */
final class RagAssembly
{
    /**
     * @param RagDataInterface[]  $payloads         Dehydrated, serializable RAG payloads
     * @param array<string,true>  $seenIds          Retrieval dedup state at assembly time.
     *                                              A later synchronous pass resumes from this
     *                                              so warm+fresh dedup identically to one pass.
     * @param string              $contextMode      'normal' | 'extended' at assembly time
     * @param int|null            $freshnessAnchor  Input-state marker at assembly time:
     *                                              last message id (history mode) or pool
     *                                              signature (pool mode). Null when not applicable.
     * @param int                 $assembledAt      Unix timestamp — for TTL / debugging.
     */
    public function __construct(
        public readonly array  $payloads,
        public readonly array  $seenIds,
        public readonly string $contextMode,
        public readonly ?int   $freshnessAnchor,
        public readonly int    $assembledAt,
    ) {
    }

    /**
     * Empty assembly — nothing retrieved. Distinct from "no cache entry":
     * a real assembly that found nothing still caches (avoids re-running a
     * miss), whereas absence means "never assembled".
     */
    public static function empty(string $contextMode, ?int $freshnessAnchor = null): self
    {
        return new self([], [], $contextMode, $freshnessAnchor, time());
    }

    public function isEmpty(): bool
    {
        foreach ($this->payloads as $p) {
            if (!$p->isEmpty()) {
                return false;
            }
        }
        return true;
    }
}
