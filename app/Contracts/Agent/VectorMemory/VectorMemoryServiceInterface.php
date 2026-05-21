<?php

namespace App\Contracts\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Interface for Vector Memory Service
 * Defines contract for vector memory operations.
 *
 * ---------------------------------------------------------------------------
 *  Domain model
 * ---------------------------------------------------------------------------
 *  Each vector memory record belongs to exactly one named "domain" — an
 *  agent-managed namespace within a single preset's memory.
 *
 *  Domains are NOT stored in a separate table. A domain exists as long as
 *  at least one record carries its name; when the last record is moved out
 *  or deleted, the domain vanishes from listDomains() naturally.
 *
 *  Conventions:
 *   - Default domain is 'global' (see VectorMemory::DEFAULT_DOMAIN). All
 *     records created without an explicit domain land here. All pre-domain
 *     records are migrated into 'global'.
 *   - Domain names are normalised to mb_strtolower(trim(...)) on input.
 *   - Names must not contain '|', ',', ':', quotes, or whitespace control
 *     chars — these would break the inline search parser and tag content.
 *   - Names are NOT restricted to ASCII; the plugin's language_mode steers
 *     the agent toward a consistent naming convention.
 *
 *  Operations that accept domain filters:
 *   - storeVectorMemory:    $config['domain']  → single string, optional
 *   - searchVectorMemories: $config['domains'] → string[], optional
 *   - searchVectorMemories: inline prefix "domain:a,b | actual query"
 *   - getVectorMemories:    array $domains parameter
 *
 *  Empty/missing domain filter ⇒ search across ALL domains of the preset.
 *
 *  $config['domains'] (when set) takes precedence over the inline prefix.
 *  This separation lets RAG configs hard-pin a domain at the wiring layer
 *  while leaving the inline form free for ad-hoc agent queries.
 */
interface VectorMemoryServiceInterface
{
    /**
     * Get paginated vector memories using Laravel pagination
     *
     * @param AiPreset $preset
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedVectorMemories(AiPreset $preset, int $perPage = 20): LengthAwarePaginator;

    /**
     * Get vector memories for preset, optionally filtered by domain(s).
     *
     * @param  AiPreset   $preset
     * @param  int|null   $limit    Max records (null = no limit)
     * @param  string[]   $domains  Domain whitelist; empty array = no filter (all domains)
     * @return Collection<int, VectorMemory>
     */
    public function getVectorMemories(AiPreset $preset, ?int $limit = null, array $domains = []): Collection;

    /**
     * Store content in vector memory.
     *
     * The target domain is taken from $config['domain']. Missing or empty
     * domain falls back to $config['default_domain'] or VectorMemory::DEFAULT_DOMAIN.
     * Invalid domain (forbidden chars or too long) makes the call fail with
     * an error result — the agent is expected to retry with a valid name.
     *
     * @param AiPreset $preset
     * @param string $content
     * @param array $config Plugin configuration. Domain-relevant keys:
     *                      - 'domain' (string|null): target domain for this record
     *                      - 'default_domain' (string): fallback when 'domain' is empty
     * @return array Result with success status, message, and 'domain' on success
     */
    public function storeVectorMemory(AiPreset $preset, string $content, array $config = []): array;

    /**
     * Search vector memories by semantic similarity.
     *
     * Domain filter resolution (first match wins):
     *   1. $config['domains'] — explicit list (used by RAG configs)
     *   2. Inline prefix in $query: "domain:a,b | actual query"
     *   3. No filter — searches all domains of the preset
     *
     * @param AiPreset $preset
     * @param string $query Search text; may carry an inline "domain:..." prefix
     * @param array $config Plugin configuration. Domain-relevant keys:
     *                      - 'domains' (string[]): hard whitelist, wins over inline
     * @return array Result with search results and the resolved 'domains' list
     */
    public function searchVectorMemories(AiPreset $preset, string $query, array $config = []): array;

    /**
     * List all domains used by this preset, with record counts.
     * Sorted by count descending. Output is intended for both UI display
     * and the [[vector_memory_domains]] placeholder in agent prompts.
     *
     * @param AiPreset $preset
     * @return array<int, array{name: string, count: int}>
     */
    public function listDomains(AiPreset $preset): array;

    /**
     * Move all records from $domain into the default domain.
     * The source domain vanishes from listDomains() afterwards (no records
     * left). Records themselves are preserved — only their label changes.
     *
     * No-op if $domain equals the default domain.
     *
     * Caller (plugin) is responsible for enforcing any "protected domains"
     * policy. This method only executes the data operation.
     *
     * @param AiPreset $preset
     * @param string $domain Name of the domain to drop
     * @param array $config Plugin configuration:
     *                      - 'default_domain' (string): override default destination
     * @return int Number of records moved
     */
    public function dropDomain(AiPreset $preset, string $domain, array $config = []): int;

    /**
     * Permanently delete all records belonging to $domain.
     *
     * Caller (plugin) is responsible for enforcing the 'domains_deletable'
     * and 'domains_protected' policies. This method only executes the data
     * operation.
     *
     * @param AiPreset $preset
     * @param string $domain Name of the domain to purge
     * @return int Number of records deleted
     */
    public function purgeDomain(AiPreset $preset, string $domain): int;

    /**
     * Get recent vector memories
     *
     * @param AiPreset $preset
     * @param int $limit
     * @return array Result with recent memories
     */
    public function getRecentVectorMemories(AiPreset $preset, int $limit = 5): array;

    /**
     * Delete specific vector memory
     *
     * @param AiPreset $preset
     * @param int $memoryId
     * @return array Result with success status
     */
    public function deleteVectorMemory(AiPreset $preset, int $memoryId): array;

    /**
     * Clear all vector memories for preset
     *
     * @param AiPreset $preset
     * @return array Result with success status
     */
    public function clearVectorMemories(AiPreset $preset): array;

    /**
     * Get vector memory statistics
     *
     * @param AiPreset $preset
     * @param array $config Plugin configuration
     * @return array Statistics data
     */
    public function getVectorMemoryStats(AiPreset $preset, array $config = []): array;

    /**
     * Update memory importance
     *
     * @param AiPreset $preset
     * @param int $memoryId
     * @param float $importance
     * @return array Result with success status
     */
    public function updateVectorMemoryImportance(AiPreset $preset, int $memoryId, float $importance): array;

    /**
     * Get vector memory by ID
     *
     * @param AiPreset $preset
     * @param int $memoryId
     * @return VectorMemory|null
     */
    public function getVectorMemoryById(AiPreset $preset, int $memoryId): ?VectorMemory;

    /**
     * Search memories by specific keywords
     *
     * @param AiPreset $preset
     * @param array $keywords
     * @return Collection
     */
    public function searchByKeywords(AiPreset $preset, array $keywords): Collection;

    /**
     * Test vector memory service connection and functionality
     *
     * @param AiPreset $preset
     * @return array
     */
    public function testConnection(AiPreset $preset): array;

    /**
     * Export vector memories using dedicated exporter service.
     * Export format v3 includes the per-record 'domain' field.
     *
     * @param AiPreset $preset
     * @return array
     */
    public function exportVectorMemories(AiPreset $preset): array;

    /**
     * Import vector memories using dedicated importer service.
     *
     * Handles export format versions transparently:
     *   - v1, v2: no 'domain' field — all imported records land in the
     *             default domain.
     *   - v3:     'domain' field per record is honoured; invalid values
     *             fall back to default with a logged warning.
     *
     * @param AiPreset $preset
     * @param string $content
     * @param bool $isJson
     * @param bool $replaceExisting
     * @param array $config
     * @return array
     */
    public function importVectorMemories(
        AiPreset $preset,
        string $content,
        bool $isJson,
        bool $replaceExisting,
        array $config
    ): array;
}
