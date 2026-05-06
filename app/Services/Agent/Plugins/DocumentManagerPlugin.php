<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Models\File;
use App\Services\Agent\FileStorage\FileService;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * DocumentManagerPlugin
 *
 * Gives the agent access to the file storage layer:
 *   - Search file chunks by meaning (embedding → TF-IDF fallback)
 *   - List files visible to the preset
 *   - Show metadata and content preview of a specific file
 *   - Delete a file by ID
 *
 * File upload/store is handled by the HTTP layer (chat UI),
 * not by the agent directly — the agent works with already-stored files.
 */
class DocumentManagerPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected FileService $fileService,
        protected LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'documents';
    }

    public function getDescription(array $config = []): string
    {
        $limit = $config['search_limit'] ?? 5;
        return "Document storage: search file contents by meaning, list, inspect and delete files. Returns up to {$limit} chunks per search.";
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'Search file contents by meaning: [documents search]how to configure database connections[/documents]',
            'List all available files: [documents list][/documents]',
            'Show file details and content preview: [documents show]42[/documents]',
            'Delete a file by ID: [documents delete]42[/documents]',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        $limit = $config['search_limit'] ?? 5;

        return [
            'name'        => 'documents',
            'description' => 'Access files stored for this preset. '
                . 'Search file contents semantically, list available files, inspect or delete them. '
                . "Search returns up to {$limit} relevant chunks with source file info. "
                . 'Use search to find information in documents before asking the user.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['search', 'list', 'show', 'delete'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'search: natural language query to find relevant content in files.',
                            'Example: "database connection settings".',
                            'list: leave empty — returns all files visible to this preset.',
                            'show: numeric file ID to inspect (metadata + content preview).',
                            'delete: numeric file ID to permanently delete.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null; // default ⚡ SUCCESS message is fine
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Document Manager',
                'description' => 'Allow the agent to search and manage files',
                'required'    => false,
            ],
            'search_limit' => [
                'type'        => 'number',
                'label'       => 'Search result limit',
                'description' => 'Maximum number of chunks returned per search',
                'min'         => 1,
                'max'         => 20,
                'value'       => 5,
                'required'    => false,
            ],
            'similarity_threshold' => [
                'type'        => 'number',
                'label'       => 'Similarity threshold',
                'description' => 'Minimum similarity score (0.0–1.0) for search results',
                'min'         => 0.0,
                'max'         => 1.0,
                'value'       => 0.2,
                'required'    => false,
            ],
            'preview_length' => [
                'type'        => 'number',
                'label'       => 'Content preview length',
                'description' => 'Characters shown in file preview (show command)',
                'min'         => 100,
                'max'         => 2000,
                'value'       => 500,
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'              => true,
            'search_limit'         => 5,
            'similarity_threshold' => 0.2,
            'preview_length'       => 500,
        ];
    }

    /**
     * Default command — proxies to search().
     * Allows: [documents]find me something[/documents]
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->search($content, $context);
    }

    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function collapseOutput(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }

    // -------------------------------------------------------------------------
    // Methods (called by CommandExecutor)
    // -------------------------------------------------------------------------

    /**
     * Search file contents by meaning.
     * [documents search]your query here[/documents]
     */
    public function search(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Document manager is disabled.';
        }

        $query = trim($content);
        if ($query === '') {
            return 'Error: Please provide a search query.';
        }

        try {
            $limit     = (int) $context->get('search_limit', 5);
            $threshold = (float) $context->get('similarity_threshold', 0.2);

            $result = $this->fileService->search(
                preset: $context->preset,
                query: $query,
                limit: $limit,
                threshold: $threshold,
            );

            if (!$result['success']) {
                return 'Error: ' . ($result['message'] ?? 'Search failed.');
            }

            if (empty($result['results'])) {
                return "No relevant content found for: \"{$query}\"";
            }

            return $this->formatSearchResults($result['results'], $query);

        } catch (\Throwable $e) {
            $this->logger->error('DocumentManagerPlugin::search error', [
                'preset_id' => $context->preset->id,
                'query'     => $query,
                'error'     => $e->getMessage(),
            ]);
            return 'Error searching documents: ' . $e->getMessage();
        }
    }

    /**
     * List all files visible to this preset.
     * [documents list][/documents]
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Document manager is disabled.';
        }

        try {
            $files = File::query()
                ->forPreset($context->preset->id)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($files->isEmpty()) {
                return 'No files available for this preset.';
            }

            $lines = ["Files ({$files->count()}):"];

            foreach ($files as $file) {
                $status  = $this->statusIcon($file->processing_status);
                $driver  = $file->storage_driver === 'sandbox' ? '🔧' : '📄';
                $scope   = $file->scope === 'global' ? ' [global]' : '';
                $chunks  = $file->is_processed ? " · {$file->chunk_count} chunks" : '';
                $lines[] = "{$driver} [{$file->id}] {$file->original_name} {$status}{$scope} · {$file->human_size}{$chunks}";
            }

            return implode("\n", $lines);

        } catch (\Throwable $e) {
            $this->logger->error('DocumentManagerPlugin::list error', [
                'preset_id' => $context->preset->id,
                'error'     => $e->getMessage(),
            ]);
            return 'Error listing files: ' . $e->getMessage();
        }
    }

    /**
     * Show file metadata and content preview.
     * [documents show]42[/documents]
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Document manager is disabled.';
        }

        $id = (int) trim($content);
        if ($id <= 0) {
            return 'Error: Please provide a valid file ID.';
        }

        try {
            $file = File::query()
                ->forPreset($context->preset->id)
                ->find($id);

            if (!$file) {
                return "File #{$id} not found or not accessible.";
            }

            $previewLength = (int) $context->get('preview_length', 500);

            return $this->formatFileDetails($file, $previewLength);

        } catch (\Throwable $e) {
            $this->logger->error('DocumentManagerPlugin::show error', [
                'preset_id' => $context->preset->id,
                'file_id'   => $id,
                'error'     => $e->getMessage(),
            ]);
            return 'Error showing file: ' . $e->getMessage();
        }
    }

    /**
     * Delete a file by ID.
     * [documents delete]42[/documents]
     */
    public function delete(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Document manager is disabled.';
        }

        $id = (int) trim($content);
        if ($id <= 0) {
            return 'Error: Please provide a valid file ID.';
        }

        try {
            $file = File::query()
                ->forPreset($context->preset->id)
                ->find($id);

            if (!$file) {
                return "File #{$id} not found or not accessible.";
            }

            $name    = $file->original_name;
            $deleted = $this->fileService->delete($file);

            return $deleted
                ? "Deleted file #{$id}: {$name}"
                : "Failed to delete file #{$id}: {$name}";

        } catch (\Throwable $e) {
            $this->logger->error('DocumentManagerPlugin::delete error', [
                'preset_id' => $context->preset->id,
                'file_id'   => $id,
                'error'     => $e->getMessage(),
            ]);
            return 'Error deleting file: ' . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Private formatters
    // -------------------------------------------------------------------------

    private function formatSearchResults(array $results, string $query): string
    {
        $lines = ["Search results for \"{$query}\" (" . count($results) . " chunks):"];

        foreach ($results as $i => $result) {
            $chunk   = $result['chunk'];
            $file    = $chunk->file;
            $score   = round($result['similarity'] * 100, 1);
            $source  = $result['source'] ?? 'search';
            $preview = $this->truncate($chunk->content, 300);

            $lines[] = '';
            $lines[] = "[" . ($i + 1) . "] {$file->original_name} (ID:{$file->id}) · chunk #{$chunk->chunk_index} · {$score}% · {$source}";
            $lines[] = $preview;
        }

        return implode("\n", $lines);
    }

    private function formatFileDetails(File $file, int $previewLength): string
    {
        $lines = [
            "File #{$file->id}: {$file->original_name}",
            "Type:    {$file->mime_type}",
            "Size:    {$file->human_size}",
            "Driver:  {$file->storage_driver}",
            "Scope:   {$file->scope}",
            "Status:  {$file->processing_status}",
            "Chunks:  {$file->chunk_count}",
            "Created: {$file->created_at->format('Y-m-d H:i')}",
        ];

        if ($file->storage_driver === 'sandbox') {
            $lines[] = "Sandbox path: ~/{$file->storage_path}";
            $lines[] = "(accessible via terminal)";
        }

        // Meta info from processor
        $meta = $file->meta ?? [];
        foreach (['page_count', 'line_count', 'sheet_names', 'encoding', 'language'] as $key) {
            if (isset($meta[$key])) {
                $value   = is_array($meta[$key]) ? implode(', ', $meta[$key]) : $meta[$key];
                $label   = ucfirst(str_replace('_', ' ', $key));
                $lines[] = "{$label}: {$value}";
            }
        }

        if (isset($meta['error'])) {
            $lines[] = "Error:   {$meta['error']}";
        }

        // Content preview from first chunk
        $firstChunk = $file->chunks()->first();
        if ($firstChunk) {
            $preview = $this->truncate($firstChunk->content, $previewLength);
            $lines[] = '';
            $lines[] = "Content preview:";
            $lines[] = $preview;
        }

        return implode("\n", $lines);
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'processed'  => '✅',
            'processing' => '⏳',
            'pending'    => '🕐',
            'failed'     => '❌',
            default      => '?',
        };
    }

    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $length * 0.8) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }
}
