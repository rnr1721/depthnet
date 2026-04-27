<?php

namespace App\Contracts\Agent\Enricher;

use App\Models\AiPreset;

interface EnricherResponseInterface
{
    /**
     * Enricher response text, ready to inject into a shortcode placeholder.
     *
     * @return string|null
     */
    public function getResponse(): ?string;

    /**
     * The secondary preset used by this enricher (RAG preset, voice preset, etc.).
     *
     * @return AiPreset|null
     */
    public function getPreset(): ?AiPreset;

    /**
     * The main preset being enriched.
     *
     * @return AiPreset
     */
    public function getMainPreset(): AiPreset;

    /**
     * IDs of records retrieved during this enrichment pass.
     * Used by the multi-RAG pipeline to deduplicate results across configs.
     *
     * Keys are namespaced strings to avoid collisions between source types:
     *   "vm:{id}"      — vector memory record
     *   "journal:{id}" — journal entry
     *   "skill:{id}"   — skill item
     *
     * @return array<string, true>
     */
    public function getRetrievedIds(): array;
}
