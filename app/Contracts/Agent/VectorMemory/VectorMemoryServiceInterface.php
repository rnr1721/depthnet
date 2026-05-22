<?php

namespace App\Contracts\Agent\VectorMemory;

use App\Models\AiPreset;
use App\Models\VectorMemory;
use App\Services\Agent\VectorMemory\VectorMemoryQuery;
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
 *   - getVectorMemories:    VectorMemoryQuery::domains
 *
 *  Empty/missing domain filter ⇒ search across ALL domains of the preset.
 *
 *  $config['domains'] (when set) takes precedence over the inline prefix.
 *  This separation lets RAG configs hard-pin a domain at the wiring layer
 *  while leaving the inline form free for ad-hoc agent queries.
 *
 * ---------------------------------------------------------------------------
 *  Temporal filtering
 * ---------------------------------------------------------------------------
 *  Vector memories can be filtered by a time range on `created_at`. The range
 *  is resolved by a shared SearchDateParserInterface, so the DSL matches
 *  what the journal supports (ISO dates, year-month, year, plus localised
 *  keywords like "yesterday" / "вчера" / "last month").
 *
 *  Sources of the time filter (first wins):
 *   1. $config['from'] / $config['to']           — Carbon, RAG-config style
 *   2. Inline "time:<expr> | rest"               — explicit, primary form
 *   3. Bare date keyword as first prefix          — fallback ("yesterday | rest")
 *   4. No filter
 *
 *  Behaviour matrix:
 *
 *    | query   | time filter | mode                              |
 *    |---------|-------------|-----------------------------------|
 *    | yes     | no          | semantic (current behaviour)      |
 *    | yes     | yes         | semantic, bounded by time window  |
 *    | no      | yes         | temporal: chronological listing   |
 *    | no      | no          | error                             |
 *
 *  In associative search, the time filter constrains the starting set of
 *  the chain; subsequent associative hops stay within that window.
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
     * Get vector memories for preset, filtered by a VectorMemoryQuery.
     *
     * The query describes WHICH records to fetch (domain whitelist, time
     * range, limit). Search algorithm parameters belong to $config in
     * search methods, not here.
     *
     * @param  AiPreset           $preset
     * @param  VectorMemoryQuery  $query  Filter criteria; defaults to "everything".
     * @return Collection<int, VectorMemory>
     */
    public function getVectorMemories(AiPreset $preset, VectorMemoryQuery $query = new VectorMemoryQuery()): Collection;

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
     * Search vector memories by semantic similarity, optionally bounded by time.
     *
     * Domain filter resolution (first match wins):
     *   1. $config['domains'] — explicit list (used by RAG configs)
     *   2. Inline prefix in $query: "domain:a,b | actual query"
     *   3. No filter — searches all domains of the preset
     *
     * Time filter resolution (first match wins):
     *   1. $config['from'] / $config['to'] — Carbon instances (RAG configs)
     *   2. Inline "time:<expr> | rest"
     *   3. Bare date keyword as first prefix ("yesterday | rest") — fallback
     *   4. No filter
     *
     * Both domain and time prefixes may appear together in any order:
     *   "time:last week | domain:work | optimization"
     *   "domain:work | time:last week | optimization"
     *
     * When the cleaned query is empty AND a time filter is set, the call
     * returns records in the window sorted by created_at descending (temporal
     * mode) — no semantic ranking. When both are empty, returns an error.
     *
     * @param AiPreset $preset
     * @param string $query Search text; may carry inline "domain:..." / "time:..." prefixes
     * @param array $config Plugin configuration. Filter-relevant keys:
     *                      - 'domains' (string[]): hard whitelist, wins over inline
     *                      - 'from' (Carbon|null): time lower bound, wins over inline
     *                      - 'to'   (Carbon|null): time upper bound, wins over inline
     * @return array Result with search results, resolved 'domains', 'from'/'to', and 'temporal' flag
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
