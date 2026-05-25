<?php

namespace App\Contracts\Agent\Enricher\Rag;

/**
 * One atomic result inside a RagSection.
 *
 * Items are the smallest unit of RAG data. They carry their content,
 * an optional relevance score, and a metadata bag with source-specific
 * payload (the original model, anchor flag, file chunk reference, etc.).
 *
 * Items do NOT know how to render themselves — their parent section's
 * renderer interprets metadata based on the section's type.
 */
interface RagItemInterface
{
    /**
     * Stable identifier for deduplication across configs.
     *
     * Same record retrieved by two RAG configs must produce the same key.
     * Format is namespaced: "vm:42", "journal:17", "skill:3.5",
     * "file_chunk:88", "ontology:5", "person_fact:12".
     */
    public function getDedupKey(): string;

    /**
     * Raw textual content of the item.
     *
     * Renderers may use this directly or pull richer data from metadata.
     */
    public function getContent(): string;

    /**
     * Relevance score, typically 0.0 - 1.0.
     *
     * Null when the concept doesn't apply (ontology snapshots,
     * journal neighbours, persons facts retrieved by Heart focus).
     */
    public function getScore(): ?float;

    /**
     * Source-specific data bag.
     *
     * The section's renderer knows what to pull out based on section type.
     *
     * Examples:
     *   - memory  : ['document' => Memory, 'composite_score' => 0.81, 'source' => 'embedding']
     *   - journal : ['entry' => JournalEntry, 'is_anchor' => true]
     *   - file    : ['chunk' => FileChunk, 'similarity' => 0.72]
     *   - person  : ['person_name' => 'Eugeny', 'fact_id' => 12]
     *   - skill   : ['skill_number' => 3, 'item_number' => 5,
     *                'skill_title' => '...', 'similarity_percent' => 87]
     *   - ontology: ['snapshot' => '...formatted text...']
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
