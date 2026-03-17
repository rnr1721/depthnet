<?php

namespace App\Contracts\Agent\Plugins;

/**
 * Contract for any document that can participate in TF-IDF similarity search.
 *
 * Implement this on any Eloquent model whose content you want to index
 * and search via TfIdfService (e.g. VectorMemory, SkillItem).
 */
interface TfIdfDocumentInterface
{
    /**
     * Return the pre-computed TF-IDF vector stored on this document.
     * Keys are stemmed tokens, values are normalised TF-IDF weights.
     *
     * @return array<string, float>
     */
    public function getTfIdfVector(): array;

    /**
     * Return the raw text content of the document.
     * Used as a seed for the next associative search step.
     *
     * @return string
     */
    public function getTextContent(): string;

    /**
     * Return the creation timestamp.
     * Used for recency boost calculation.
     *
     * @return \Carbon\Carbon|null
     */
    public function getCreatedAt(): ?\Carbon\Carbon;
}
