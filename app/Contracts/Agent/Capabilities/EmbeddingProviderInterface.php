<?php

namespace App\Contracts\Agent\Capabilities;

/**
 * Contract for embedding capability providers.
 *
 * Extends the base capability contract with embedding-specific operations:
 * converting text into dense float vectors for semantic similarity search.
 */
interface EmbeddingProviderInterface extends CapabilityProviderInterface
{
    /**
     * Capability type identifier stored in preset_capability_configs.capability.
     */
    public const CAPABILITY = 'embedding';

    /**
     * Embed a single text into a dense float vector.
     *
     * @param  string     $text
     * @return float[]|null  Returns null on failure.
     */
    public function embed(string $text): ?array;

    /**
     * Embed multiple texts in a single API call where supported.
     *
     * @param  string[]  $texts
     * @return array<int, float[]|null>  Indexed array, null for failed items.
     */
    public function embedBatch(array $texts): array;

    /**
     * Output vector dimension for this driver/model combination.
     * Example: 1024 (bge-m3), 1536 (text-embedding-3-small), 3072 (large).
     */
    public function getDimension(): int;
}
