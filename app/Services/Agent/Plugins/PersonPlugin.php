<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * PersonPlugin — structured memory for people.
 *
 * person_name stores the full alias string: "Женя / Жэка / James Kvakiani"
 * Facts are individual records identified by system ID.
 *
 * Commands:
 *   [person]Женя | loves punk aesthetic[/person]      — add fact (creates person if new)
 *   [person recall]Женя[/person]                      — recall by name/alias
 *   [person recall]1[/person]                         — recall by fact ID
 *   [person find]Вася[/person]                        — search across all aliases
 *   [person search]punk aesthetic[/person]            — semantic search across all facts
 *   [person alias add]1 | Жэка[/person]               — add alias (identified by any fact ID)
 *   [person alias remove]1 | Жэка[/person]            — remove alias
 *   [person delete]42[/person]                        — delete fact by ID
 *   [person forget]Женя[/person]                      — remove all facts about person
 *   [person list][/person]                            — list all known people
 */
class PersonPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected PersonMemoryServiceInterface $personMemoryService,
        protected LoggerInterface              $logger,
    ) {
        $this->initializeConfig();
    }

    public function getName(): string
    {
        return 'person';
    }

    public function getDescription(): string
    {
        return 'Structured memory for people. Store facts, manage aliases, search semantically. person_name stores all aliases as "Primary / Alias1 / Alias2".';
    }

    public function getInstructions(): array
    {
        return [
            'Add fact:              [person]Женя | loves punk aesthetic and travel[/person]',
            'Recall by name/id:     [person recall]Женя[/person]  or  [person recall]1[/person]',
            'Find by any alias:     [person find]James Kvakiani[/person]',
            'Semantic search:       [person search]developer Kharkiv[/person]',
            'Add alias:             [person alias add]1 | Жэка[/person]',
            'Remove alias:          [person alias remove]1 | Жэка[/person]',
            'Delete fact:           [person delete]42[/person]',
            'Forget person:         [person forget]Женя[/person]',
            'List all people:       [person list][/person]',
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Person Memory Plugin',
                'description' => 'Allow storing structured facts about people',
                'required'    => false,
            ],
            'search_limit' => [
                'type'        => 'number',
                'label'       => 'Search results limit',
                'description' => 'Max facts returned by semantic search',
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
            $l = (int) $config['search_limit'];
            if ($l < 1 || $l > 20) {
                $errors['search_limit'] = 'Search limit must be between 1 and 20.';
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

    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — add a fact.
     * [person]Женя | loves punk aesthetic[/person]
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return 'Error: Invalid format. Use correct syntax';
        }

        $personName = trim($parts[0]);
        $fact       = trim($parts[1]);

        if (empty($personName)) {
            return 'Error: Person name cannot be empty.';
        }

        $result = $this->personMemoryService->addFact($preset, $personName, $fact);
        return $result['message'];
    }

    /**
     * [person recall]Женя[/person]  or  [person recall]1[/person]
     */
    public function recall(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $nameOrId = trim($content);
        if (empty($nameOrId)) {
            return 'Error: Provide a name or fact ID. Use person recall.';
        }

        $result = $this->personMemoryService->recallPerson($preset, $nameOrId);
        return $result['message'];
    }

    /**
     * [person find]James Kvakiani[/person]
     */
    public function find(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $term = trim($content);
        if (empty($term)) {
            return 'Error: Provide a search term. Use person find';
        }

        $result = $this->personMemoryService->findByMention($preset, $term);
        return $result['message'];
    }

    /**
     * [person search]punk aesthetic[/person]
     */
    public function search(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $query = trim($content);
        if (empty($query)) {
            return 'Error: Provide a search query. Use person search';
        }

        $limit  = (int) ($this->config['search_limit'] ?? 5);
        $result = $this->personMemoryService->searchFacts($preset, $query, $limit);
        return $result['message'];
    }

    /**
     * [person alias add]1 | Жэка[/person]
     * [person alias remove]1 | Жэка[/person]
     *
     * Routed here as method "alias" with content "add|1|Жэка" or "remove|1|Жэка".
     * The PluginMethodTrait routes [person alias] as method "alias".
     * We split the subcommand from content manually.
     */
    public function alias(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        // content format: "add|1|Жэка" or "remove|1|Жэка"
        $parts = array_map('trim', explode('|', $content, 3));

        if (count($parts) < 3) {
            return 'Error: Invalid format. Use correct syntax to work with aliases';
        }

        [$subcommand, $idStr, $alias] = $parts;
        $factId = (int) $idStr;

        if ($factId <= 0) {
            return 'Error: Provide a valid fact ID (any fact belonging to that person).';
        }

        return match (strtolower($subcommand)) {
            'add'    => $this->personMemoryService->addAlias($preset, $factId, $alias)['message'],
            'remove' => $this->personMemoryService->removeAlias($preset, $factId, $alias)['message'],
            default  => 'Error: Unknown alias subcommand. Use "add" or "remove".',
        };
    }

    /**
     * [person delete]42[/person]
     */
    public function delete(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $factId = (int) trim($content);
        if ($factId <= 0) {
            return 'Error: Provide a valid fact ID. Use person delete using ID';
        }

        $result = $this->personMemoryService->deleteFact($preset, $factId);
        return $result['message'];
    }

    /**
     * [person forget]Женя[/person]
     */
    public function forget(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $nameOrId = trim($content);
        if (empty($nameOrId)) {
            return 'Error: Provide a name or fact ID. Use person forget with name';
        }

        $result = $this->personMemoryService->forgetPerson($preset, $nameOrId);
        return $result['message'];
    }

    /**
     * [person list][/person]
     */
    public function list(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Person memory plugin is disabled.';
        }

        $result = $this->personMemoryService->listPeople($preset);
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

    public function pluginReady(AiPreset $preset): void
    {
        // No placeholders — PersonContextEnricher handles [[persons_context]]
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }
}
