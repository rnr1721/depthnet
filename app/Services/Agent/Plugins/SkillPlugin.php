<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * SkillPlugin — persistent knowledge base with semantic search.
 *
 * A skill is a named collection of knowledge items.
 * Items are indexed with TF-IDF so the agent can search across all skills
 * by meaning, not just by exact keyword.
 *
 * Active skills (title + description) are always visible in Dynamic Context
 * via the {{skills}} placeholder so the agent knows what knowledge it has
 * and can open the right skill when needed.
 *
 * Commands:
 *   [skill]title | first item content[/skill]            — create skill, add first item
 *   [skill]title[/skill]                                 — create empty skill
 *   [skill add]1 | item content[/skill]                  — add item to skill #1
 *   [skill update]1.2 | new content[/skill]              — update item 1.2
 *   [skill delete]1[/skill]                              — delete entire skill #1
 *   [skill delete]1.2[/skill]                            — delete item 1.2
 *   [skill show]1[/skill]                                — show all items of skill #1
 *   [skill list][/skill]                                 — list all skills (self-closing)
 *   [skill search]query text[/skill]                     — semantic search across items
 */
class SkillPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected SkillServiceInterface $skillService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    public function getName(): string
    {
        return 'skill';
    }

    public function getDescription(): string
    {
        return 'Persistent knowledge base. Store reusable knowledge as named skills with items. Items are semantically searchable via TF-IDF.';
    }

    public function getInstructions(): array
    {
        return [
            'Create skill with first item: [skill]PostgreSQL | Use EXPLAIN ANALYZE to inspect query plans[/skill]',
            'Create empty skill: [skill]Code style[/skill]',
            'Add item to skill: [skill add]1 | Partial indexes speed up filtered queries significantly[/skill]',
            'Update item: [skill update]1.2 | Updated content here[/skill]',
            'Delete item: [skill delete]1.2[/skill]',
            'Delete entire skill: [skill delete]1[/skill]',
            'Show skill with all items: [skill show]1[/skill]',
            'List all skills: [skill list][/skill]',
            'Search items by meaning: [skill search]how to speed up slow queries[/skill]',
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Skill Plugin',
                'description' => 'Allow persistent skill knowledge base',
                'required'    => false,
            ],
            'search_limit' => [
                'type'        => 'number',
                'label'       => 'Search Results Limit',
                'description' => 'Maximum number of items returned by semantic search',
                'min'         => 1,
                'max'         => 20,
                'value'       => 5,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['search_limit'])) {
            $limit = (int) $config['search_limit'];
            if ($limit < 1 || $limit > 20) {
                $errors['search_limit'] = 'Search limit must be between 1 and 20';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'      => true,
            'search_limit' => 5,
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — create skill.
     * Format: "title" or "title | first item content"
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $parts = explode('|', $content, 2);
        $title = trim($parts[0]);

        if (empty($title)) {
            return "Error: Skill title cannot be empty. Use [skill]title[/skill] or [skill]title | first item[/skill]";
        }

        $firstItem = isset($parts[1]) ? trim($parts[1]) : null;

        $result = $this->skillService->addSkill($preset, $title, null, $firstItem ?: null);
        return $result['message'];
    }

    /**
     * Add item to existing skill.
     * Format: "skillNumber | item content"
     */
    public function add(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $parts = explode('|', $content, 2);

        if (count($parts) !== 2) {
            return "Error: Invalid format. Use [skill add]1 | item content[/skill]";
        }

        $skillNumber = (int) trim($parts[0]);
        $itemContent = trim($parts[1]);

        if (empty($itemContent)) {
            return "Error: Item content cannot be empty.";
        }

        $result = $this->skillService->addItem($preset, $skillNumber, $itemContent);
        return $result['message'];
    }

    /**
     * Update an item.
     * Format: "skillNumber.itemNumber | new content"
     * Example: "1.2 | Updated content"
     */
    public function update(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $parts = explode('|', $content, 2);

        if (count($parts) !== 2) {
            return "Error: Invalid format. Use [skill update]1.2 | new content[/skill]";
        }

        [$skillNumber, $itemNumber] = $this->parseItemRef(trim($parts[0]));

        if ($skillNumber === null || $itemNumber === null) {
            return "Error: Invalid item reference. Use format 1.2 (skill.item)";
        }

        $newContent = trim($parts[1]);

        if (empty($newContent)) {
            return "Error: Item content cannot be empty.";
        }

        $result = $this->skillService->updateItem($preset, $skillNumber, $itemNumber, $newContent);
        return $result['message'];
    }

    /**
     * Delete a skill or a single item.
     * Format: "skillNumber" → delete whole skill
     *         "skillNumber.itemNumber" → delete one item
     */
    public function delete(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $ref = trim($content);

        if (str_contains($ref, '.')) {
            [$skillNumber, $itemNumber] = $this->parseItemRef($ref);

            if ($skillNumber === null || $itemNumber === null) {
                return "Error: Invalid reference. Use 1 to delete a skill or 1.2 to delete an item.";
            }

            $result = $this->skillService->deleteItem($preset, $skillNumber, $itemNumber);
        } else {
            $skillNumber = (int) $ref;
            $result      = $this->skillService->deleteSkill($preset, $skillNumber);
        }

        return $result['message'];
    }

    /**
     * Show full skill with all items.
     * Format: "skillNumber"
     */
    public function show(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $skillNumber = (int) trim($content);
        $result      = $this->skillService->showSkill($preset, $skillNumber);
        return $result['message'];
    }

    /**
     * List all skills (self-closing tag).
     */
    public function list(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $result = $this->skillService->listSkills($preset);
        return $result['message'];
    }

    /**
     * Semantic search across all skill items.
     * Format: "query text"
     */
    public function search(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Skill plugin is disabled.";
        }

        $query = trim($content);

        if (empty($query)) {
            return "Error: Search query cannot be empty.";
        }

        $limit  = $this->config['search_limit'] ?? 5;
        $result = $this->skillService->searchItems($preset, $query, $limit);
        return $result['message'];
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface boilerplate
    // -------------------------------------------------------------------------

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }

    public function pluginReady(AiPreset $preset): void
    {
        $scope = $this->shortcodeScopeResolver->preset($preset->getId());

        $this->placeholderService->registerDynamic(
            'skills',
            'List of available skills with item counts',
            function () use ($preset) {
                return $this->skillService->getSkillsForContext($preset);
            },
            $scope
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse "skillNumber.itemNumber" reference.
     * Returns [skillNumber, itemNumber] or [null, null] on failure.
     *
     * @return array{int|null, int|null}
     */
    private function parseItemRef(string $ref): array
    {
        $parts = explode('.', $ref, 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        $skill = (int) trim($parts[0]);
        $item  = (int) trim($parts[1]);

        if ($skill <= 0 || $item <= 0) {
            return [null, null];
        }

        return [$skill, $item];
    }
}
