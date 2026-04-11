<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * RagQueryPlugin
 *
 * Allows the agent to explicitly set one or more RAG search queries for the
 * next thinking cycle, bypassing the automatic query formulation via the RAG
 * preset model.
 *
 * When the agent uses [rag query]...[/rag], the query is appended to a
 * pending list stored in preset metadata. Multiple calls within the same cycle
 * accumulate — they do NOT overwrite each other.
 *
 * On the next cycle, RagContextEnricher checks for pending queries and uses
 * them directly (running flat + journal search for each) instead of calling
 * formulateQuery(). After use, the pending list is cleared automatically.
 *
 * If no [rag query] command was issued, RAG falls back to automatic query
 * formulation as usual — full backward compatibility.
 */
class RagQueryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'rag';
    public const META_KEY    = 'pending_queries';

    /** Maximum number of queries that can be queued per cycle. */
    private const MAX_QUERIES = 5;

    /** Maximum length of a single query string. */
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        protected LoggerInterface                        $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
    ) {
        $this->initializeConfig();
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Queue one or more explicit RAG search queries for the next cycle. '
            . 'Multiple calls accumulate — each adds a new query angle instead of overwriting.';
    }

    /** @inheritDoc */
    public function getInstructions(): array
    {
        return [
            'Add a RAG query for next cycle (call multiple times for different aspects): [rag query]your search query here[/rag]',
            'Show current pending RAG queries: [rag show][/rag]',
            'Clear all pending RAG queries: [rag clear][/rag]',
        ];
    }

    /** @inheritDoc */
    public function execute(string $content, AiPreset $preset): string
    {
        return "Invalid format. Use '[rag query]your query[/rag]', '[rag show][/rag]', or '[rag clear][/rag]'";
    }

    /**
     * Append a pending RAG query for the next cycle.
     * Each call adds to the list — does NOT overwrite previous queries.
     */
    public function query(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: RAG query plugin is disabled.';
        }

        $query = $this->sanitizeQuery($content);

        if (empty($query)) {
            return 'Error: RAG query cannot be empty.';
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return sprintf('Error: RAG query is too long (max %d characters).', self::MAX_QUERY_LENGTH);
        }

        $queries = $this->loadQueries($preset);

        if (count($queries) >= self::MAX_QUERIES) {
            return sprintf(
                'Error: RAG query limit reached (%d max per cycle). Use [rag clear][/rag] to reset.',
                self::MAX_QUERIES
            );
        }

        $queries[] = $query;
        $this->saveQueries($preset, $queries);

        $this->logger->info('RagQueryPlugin: query appended', [
            'preset_id'   => $preset->getId(),
            'query'       => $query,
            'total_count' => count($queries),
        ]);

        $total = count($queries);
        return $total === 1
            ? "RAG query queued for next cycle: \"{$query}\""
            : "RAG query queued for next cycle: \"{$query}\" (total queued: {$total})";
    }

    /**
     * Show current pending RAG queries (if any).
     */
    public function show(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: RAG query plugin is disabled.';
        }

        $queries = $this->loadQueries($preset);

        if (empty($queries)) {
            return 'No pending RAG queries. Automatic query formulation will be used on next cycle.';
        }

        $lines = ['Pending RAG queries:'];
        foreach ($queries as $i => $q) {
            $lines[] = sprintf('  %d. "%s"', $i + 1, $q);
        }

        return implode("\n", $lines);
    }

    /**
     * Clear all pending RAG queries.
     */
    public function clear(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: RAG query plugin is disabled.';
        }

        $this->pluginMetadata->remove($preset, self::PLUGIN_NAME, self::META_KEY);

        return 'All pending RAG queries cleared. Automatic query formulation will be used on next cycle.';
    }

    /** @inheritDoc */
    public function pluginReady(AiPreset $preset): void
    {
        // No placeholders needed for this plugin
    }

    /** @inheritDoc */
    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    /** @inheritDoc */
    public function getCustomErrorMessage(): ?string
    {
        return 'Error: RAG query command failed.';
    }

    /** @inheritDoc */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable RAG Query Plugin',
                'description' => 'Allow agent to queue explicit RAG queries for next cycle',
                'required'    => false,
            ],
        ];
    }

    /** @inheritDoc */
    public function validateConfig(array $config): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
        ];
    }

    /** @inheritDoc */
    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

    /** @inheritDoc */
    public function canBeMerged(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function getMergeSeparator(): ?string
    {
        return null;
    }

    /** @inheritDoc */
    public function getSelfClosingTags(): array
    {
        return ['show', 'clear'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Load the current pending query list from metadata.
     *
     * @return string[]
     */
    private function loadQueries(AiPreset $preset): array
    {
        $raw = $this->pluginMetadata->get($preset, self::PLUGIN_NAME, self::META_KEY);

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the query list to metadata as JSON.
     *
     * @param string[] $queries
     */
    private function saveQueries(AiPreset $preset, array $queries): void
    {
        $this->pluginMetadata->set(
            $preset,
            self::PLUGIN_NAME,
            self::META_KEY,
            json_encode(array_values($queries), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Strip surrounding quotes and normalise whitespace from a raw query string.
     */
    private function sanitizeQuery(string $raw): string
    {
        $trimmed = trim($raw);
        // Remove surrounding quote characters (straight, typographic)
        $trimmed = trim($trimmed, "\"'«»\u{201C}\u{201D}\u{2018}\u{2019}");

        return preg_replace('/\s+/', ' ', $trimmed);
    }
}