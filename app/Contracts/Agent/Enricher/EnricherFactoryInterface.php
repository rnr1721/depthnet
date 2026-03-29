<?php

namespace App\Contracts\Agent\Enricher;

interface EnricherFactoryInterface
{
    /**
     * Returns the context enricher (inner voice / cycle prompt).
     * Responsible for injecting [[inner_voice]] into the agent's system prompt.
     *
     * @return ContextEnricherInterface
     */
    public function makeContextEnricher(): ContextEnricherInterface;

    /**
     * Returns the RAG enricher.
     * Responsible for vector search and injecting [[rag_context]] into the agent's system prompt.
     *
     * @return RagContextEnricherInterface
     */
    public function makeRagEnricher(): RagContextEnricherInterface;

    /**
     * Returns the Person context enricher.
     * Responsible for building [[persons_context]] from person memory facts,
     * using Heart focus when available, falling back to semantic search.
     */
    public function makePersonEnricher(): PersonContextEnricherInterface;
}
