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
 * Allows the agent to explicitly set a RAG search query for the next thinking
 * cycle, bypassing the automatic query formulation via the RAG preset model.
 *
 * When the agent uses [rag query]...[/rag], the query is stored in preset
 * metadata. On the next cycle, RagContextEnricher checks for a pending query
 * and uses it directly instead of calling formulateQuery(). After use, the
 * pending query is cleared automatically.
 *
 * If no [rag query] command was issued, RAG falls back to automatic query
 * formulation as usual — full backward compatibility.
 */
class RagQueryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME    = 'rag';
    public const META_KEY       = 'pending_query';

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
        return 'Set an explicit RAG search query for the next cycle, bypassing automatic query formulation.';
    }

    /** @inheritDoc */
    public function getInstructions(): array
    {
        return [
            'Set explicit RAG query for next cycle: [rag query]your search query here[/rag]',
            'Show current pending RAG query: [rag show][/rag]',
            'Clear pending RAG query: [rag clear][/rag]',
        ];
    }

    /** @inheritDoc */
    public function execute(string $content, AiPreset $preset): string
    {
        return "Invalid format. Use '[rag query]your query[/rag]', '[rag show][/rag]', or '[rag clear][/rag]'";
    }

    /**
     * Set a pending RAG query for the next cycle.
     */
    public function query(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: RAG query plugin is disabled.';
        }

        $query = trim($content);

        if (empty($query)) {
            return 'Error: RAG query cannot be empty.';
        }

        if (mb_strlen($query) > 200) {
            return 'Error: RAG query is too long (max 200 characters).';
        }

        $this->pluginMetadata->set($preset, self::PLUGIN_NAME, self::META_KEY, $query);

        $this->logger->info('RagQueryPlugin: pending query set', [
            'preset_id' => $preset->getId(),
            'query'     => $query,
        ]);

        return "RAG query set for next cycle: \"{$query}\"";
    }

    /**
     * Show the current pending RAG query (if any).
     */
    public function show(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: RAG query plugin is disabled.';
        }

        $pending = $this->pluginMetadata->get($preset, self::PLUGIN_NAME, self::META_KEY);

        if (empty($pending)) {
            return 'No pending RAG query. Automatic query formulation will be used on next cycle.';
        }

        return "Pending RAG query: \"{$pending}\"";
    }

    /**
     * Clear the pending RAG query.
     */
    public function clear(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: RAG query plugin is disabled.';
        }

        $this->pluginMetadata->remove($preset, self::PLUGIN_NAME, self::META_KEY);

        return 'Pending RAG query cleared. Automatic query formulation will be used on next cycle.';
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
                'description' => 'Allow agent to set explicit RAG queries for next cycle',
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
}
