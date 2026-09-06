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
     * Structured payload accompanying the text response.
     *
     * Returns null for enrichers that don't (yet) produce structured data.
     * RAG enrichers return RagDataInterface; future enrichers may return
     * their own specialized payload types.
     *
     * Used by ContextBuilders to aggregate results across multiple configs
     * without re-parsing the text response.
     */
    public function getResponseData(): ?EnricherPayloadInterface;

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

    /**
     * Text to persist as a system message for UI visibility, or null.
     *
     * The enricher no longer writes this itself — it only carries the text.
     * The pipeline service decides whether to persist it: the real cycle does,
     * warm-up suppresses it. This keeps the enricher free of DB side effects.
     *
     * @return string|null
     */
    public function getSystemMessage(): ?string;

    /**
     * Preset id the system message belongs to (the RAG preset), or null.
     * Used by the pipeline as the message's preset_id when persisting.
     *
     * @return int|null
     */
    public function getSystemMessagePresetId(): ?int;
}
