<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * MemoryPlugin — stateless.
 *
 * Persistent memory storage via MemoryService. All preset-specific config
 * (memory_limit, cleanup strategy, etc.) arrives via PluginExecutionContext
 * and is forwarded to the service as needed.
 */
class MemoryPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected MemoryServiceInterface $memoryService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'memory';
    }

    public function getDescription(array $config = []): string
    {
        $limit = $config['memory_limit'] ?? 2000;

        if ($config['memory_code_units'] ?? false) {
            return "Persistent memory storage for notes and consciousness modules. Limit is {$limit} symbols. Remember important information and store executable functions that define how you think, survive, and make decisions.";
        }

        return "Persistent memory storage with structured list format. Limit is {$limit} symbols. Use it to remember important information between conversations.";
    }

    public function getInstructions(array $config = []): array
    {
        if ($config['memory_code_units'] ?? false) {
            return [
                'Add consciousness module: [memory]def survival_check(self): return self.vitality > 0.5[/memory]',
                'Add memory note: [memory]Completed task: Created users table successfully[/memory]',
                'To delete specific memory item: first use [memory search]keyword[/memory] to find the item number, then [memory delete]N[/memory]',
                'Show current memory: [memory show][/memory]',
                'Search memory: [memory search]database[/memory]',
                'Get memory stats: [memory stats][/memory]'
            ];
        }
        return [
            'Add new memory item: [memory]Completed task: Created users table successfully[/memory]',
            'To delete specific memory item: first use [memory search]keyword[/memory] to find the item number, then [memory delete]N[/memory]',
            'Show current memory: [memory show][/memory]',
            'Search memory: [memory search]database[/memory]',
            'Get memory stats: [memory stats][/memory]'
        ];
    }

    /**
     * Tool schema for tool_calls mode. Static — does not vary per preset.
     */
    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'memory',
            'description' => 'Persistent flat notepad. '
                . 'Use prefixes: IDENTITY for who you are, R(rule) for behavioral rules, S(strategy) for approaches. '
                . 'Memory content injected into system prompt. I can modify my own code and rules by writing to memory. Use for survival instincts, identity anchors, and important facts.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['execute', 'show', 'delete', 'clear'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'execute (add item): the text to append.',
                            'show: leave empty to show all items.',
                            'delete: numeric item index to remove, e.g. "3".',
                            'clear: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return "Memory operation completed successfully.";
    }

    public function getCustomErrorMessage(): ?string
    {
        return "Error: Memory operation failed. Why?";
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Memory Plugin',
                'description' => 'Allow persistent memory operations',
                'required' => false
            ],
            'memory_code_units' => [
                'type' => 'checkbox',
                'label' => 'Memory Code Execution',
                'description' => 'Enable storing and executing Python code in memory. AI can create self-modifying behavioral modules and survival instincts.',
                'value' => true,
                'required' => false
            ],
            'memory_limit' => [
                'type' => 'number',
                'label' => 'Memory Limit (characters)',
                'description' => 'Maximum number of characters in memory',
                'min' => 100,
                'max' => 10000,
                'value' => 2000,
                'required' => false
            ],
            'auto_cleanup' => [
                'type' => 'checkbox',
                'label' => 'Auto Cleanup',
                'description' => 'Automatically trim memory when limit is exceeded',
                'value' => true,
                'required' => false
            ],
            'cleanup_strategy' => [
                'type' => 'select',
                'label' => 'Cleanup Strategy',
                'description' => 'How to handle memory overflow',
                'options' => [
                    'truncate_old' => 'Remove oldest content',
                    'truncate_new' => 'Truncate new content',
                    'compress' => 'Compress content',
                    'reject' => 'Reject new content'
                ],
                'value' => 'truncate_old',
                'required' => false
            ],
            'enable_versioning' => [
                'type' => 'checkbox',
                'label' => 'Enable Versioning',
                'description' => 'Keep backup of previous memory states',
                'value' => false,
                'required' => false
            ],
            'max_versions' => [
                'type' => 'number',
                'label' => 'Max Versions',
                'description' => 'Maximum number of memory versions to keep',
                'min' => 1,
                'max' => 10,
                'value' => 3,
                'required' => false
            ]
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['memory_limit'])) {
            $limit = (int) $config['memory_limit'];
            if ($limit < 100 || $limit > 10000) {
                $errors['memory_limit'] = 'Memory limit must be between 100 and 10000 characters';
            }
        }

        if (isset($config['max_versions'])) {
            $versions = (int) $config['max_versions'];
            if ($versions < 1 || $versions > 10) {
                $errors['max_versions'] = 'Max versions must be between 1 and 10';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'memory_code_units' => false,
            'memory_limit' => 2000,
            'auto_cleanup' => true,
            'cleanup_strategy' => 'truncate_old',
            'enable_versioning' => false,
            'max_versions' => 3,
        ];
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        return $this->append($content, $context);
    }

    public function replace(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        $result = $this->memoryService->replaceMemory($context->preset, $content, $context->config);
        return $result['message'];
    }

    public function append(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        $result = $this->memoryService->addMemoryItem($context->preset, $content, $context->config);
        return $result['message'];
    }

    public function delete(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        $itemNumber = (int) trim($content);
        $result = $this->memoryService->deleteMemoryItem($context->preset, $itemNumber, $context->config);
        return $result['message'];
    }

    public function clear(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        $result = $this->memoryService->clearMemory($context->preset, $context->config);
        return $result['message'];
    }

    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $formatted = $this->memoryService->getFormattedMemory($context->preset);

            if (empty($formatted)) {
                return "Memory is empty.";
            }

            $stats = $this->memoryService->getMemoryStats($context->preset, $context->config);

            return "Current memory content ({$stats['total_items']} items, {$stats['total_length']}/{$stats['limit']} chars):\n" . $formatted;

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::show error: " . $e->getMessage());
            return "Error reading memory: " . $e->getMessage();
        }
    }

    public function search(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $query = trim($content);
            if (empty($query)) {
                return "Error: Search query cannot be empty.";
            }

            $results = $this->memoryService->searchMemory($context->preset, $query);

            if ($results->isEmpty()) {
                return "No memory items found matching '{$query}'.";
            }

            $formatted = [];
            foreach ($results as $item) {
                $position = $item->position;
                $formatted[] = "{$position}. {$item->content}";
            }

            $count = $results->count();
            return "Found {$count} memory item(s) matching '{$query}':\n" . implode("\n", $formatted);

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::search error: " . $e->getMessage());
            return "Error searching memory: " . $e->getMessage();
        }
    }

    public function stats(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Memory plugin is disabled.";
        }

        try {
            $stats = $this->memoryService->getMemoryStats($context->preset, $context->config);

            $status = 'Normal';
            if ($stats['is_over_limit']) {
                $status = 'Over Limit';
            } elseif ($stats['is_near_limit']) {
                $status = 'Near Limit';
            }

            return "Memory Statistics:\n" .
                   "- Total items: {$stats['total_items']}\n" .
                   "- Total length: {$stats['total_length']}/{$stats['limit']} characters\n" .
                   "- Usage: {$stats['usage_percentage']}%\n" .
                   "- Status: {$status}";

        } catch (\Throwable $e) {
            $this->logger->error("MemoryPlugin::stats error: " . $e->getMessage());
            return "Error getting memory stats: " . $e->getMessage();
        }
    }

    /**
     * Get memory service instance for external use.
     * Public, but not a command method (not in EXCLUDED_METHODS in trait —
     * however starts with "get" and isn't called via [memory ...] tags).
     */
    public function getMemoryService(): MemoryServiceInterface
    {
        return $this->memoryService;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic('notepad_content', 'Persistent memory content', function () use ($context) {
            return $this->memoryService->getFormattedMemory($context->preset);
        }, $scope);
    }

    public function getSelfClosingTags(): array
    {
        return ['clear', 'show', 'stats'];
    }
}
