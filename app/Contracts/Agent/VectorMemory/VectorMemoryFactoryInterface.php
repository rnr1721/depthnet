<?php

namespace App\Contracts\Agent\VectorMemory;

/**
 * Factory interface for creating VectorMemory service instances.
 *
 * Two orthogonal dimensions:
 *
 *   MODE  — how search behaves:
 *     MODE_FLAT        — single-pass similarity search, return top-K
 *     MODE_ASSOCIATIVE — chain traversal from top results through related nodes
 *
 *   ENGINE — how similarity is computed:
 *     ENGINE_TFIDF     — sparse TF-IDF keyword vectors (no external API)
 *     ENGINE_EMBEDDING — dense float vectors from an embedding provider
 *                        (falls back to ENGINE_TFIDF when unavailable)
 *
 * Usage:
 *   $factory->make()
 *       // → flat TF-IDF (default, safe everywhere)
 *
 *   $factory->make(self::MODE_ASSOCIATIVE)
 *       // → associative TF-IDF
 *
 *   $factory->make(self::MODE_FLAT, self::ENGINE_EMBEDDING)
 *       // → flat semantic search
 *
 *   $factory->make(self::MODE_ASSOCIATIVE, self::ENGINE_EMBEDDING)
 *       // → semantic search + graph-based chain traversal (best quality)
 */
interface VectorMemoryFactoryInterface
{
    // ── Search mode ───────────────────────────────────────────────────────────

    /** Single-pass similarity search — return top-K results directly. */
    public const MODE_FLAT = 'flat';

    /** Chain traversal — seed from top results, walk related nodes, composite scoring. */
    public const MODE_ASSOCIATIVE = 'associative';

    // ── Similarity engine ─────────────────────────────────────────────────────

    /** Sparse TF-IDF keyword vectors. Always available, no external API needed. */
    public const ENGINE_TFIDF = 'tfidf';

    /**
     * Dense float vectors from a configured embedding provider.
     * Automatically falls back to ENGINE_TFIDF when no capability is configured.
     */
    public const ENGINE_EMBEDDING = 'embedding';

    /**
     * Create (or return a cached) VectorMemory service instance.
     *
     * @param  string  $mode    One of the MODE_* constants. Default: MODE_FLAT.
     * @param  string  $engine  One of the ENGINE_* constants. Default: ENGINE_TFIDF.
     * @return VectorMemoryServiceInterface
     * @throws \InvalidArgumentException  When an unknown mode/engine combination is requested.
     */
    public function make(
        string $mode   = self::MODE_FLAT,
        string $engine = self::ENGINE_TFIDF,
    ): VectorMemoryServiceInterface;
}
