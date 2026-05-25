<?php

namespace App\Contracts\Agent\Enricher;

/**
 * Marker interface for structured enricher payloads.
 *
 * Enrichers may return structured data alongside their text response,
 * so downstream services (aggregators, formatters) can work with the
 * raw data instead of re-parsing strings.
 *
 * Specializations:
 *   - Rag\RagDataInterface  — sections retrieved from RAG sources
 *   - (future: InnerVoicePayloadInterface, etc.)
 *
 * Pure marker — concrete payload types declare their own methods.
 */
interface EnricherPayloadInterface
{
}
