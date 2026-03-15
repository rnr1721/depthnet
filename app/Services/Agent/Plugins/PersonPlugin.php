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
 * PersonPlugin - structured memory for people.
 *
 * Stores numbered facts about individuals independently of general memory.
 * Each person has their own fact list that can be updated incrementally.
 *
 * Workflow:
 *   [person]Zhenya | loves punk aesthetic and travel[/person]  — add fact
 *   [person recall]Zhenya[/person]                             — show all facts
 *   [person delete]Zhenya|3[/person]                           — delete fact #3
 *   [person forget]Zhenya[/person]                             — remove all facts
 *   [person list][/person]                                     — list all people
 */
class PersonPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected PersonMemoryServiceInterface $personMemoryService,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'person';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Structured memory for people. Store and recall facts about individuals across conversations.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Add fact about person: [person]Zhenya | loves punk aesthetic and travel[/person]',
            'Recall all facts about person: [person recall]Zhenya[/person]',
            'Delete specific fact: [person delete]Zhenya|3[/person]',
            'To update a fact: first [person recall]Name[/person] to see fact numbers, then [person delete]Name|N[/person] and add new fact',
            'Forget all facts about person: [person forget]Zhenya[/person]',
            'List all people in memory: [person list][/person]',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Person Memory Plugin',
                'description' => 'Allow storing facts about people',
                'required' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        return $this->isEnabled();
    }

    /**
     * Default execute — add a fact.
     * Format: "PersonName | fact content"
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Person memory plugin is disabled.";
        }

        $parts = explode('|', $content, 2);

        if (count($parts) !== 2) {
            return "Error: Invalid format. Use [person]Name | fact about them[/person]";
        }

        $personName = trim($parts[0]);
        $fact = trim($parts[1]);

        if (empty($personName)) {
            return "Error: Person name cannot be empty.";
        }

        $result = $this->personMemoryService->addFact($preset, $personName, $fact);
        return $result['message'];
    }

    /**
     * Recall all facts about a person
     */
    public function recall(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Person memory plugin is disabled.";
        }

        $personName = trim($content);
        if (empty($personName)) {
            return "Error: Person name cannot be empty.";
        }

        $result = $this->personMemoryService->recallPerson($preset, $personName);
        return $result['message'];
    }

    /**
     * Delete a specific fact about a person
     * Format: "PersonName|factNumber"
     */
    public function delete(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Person memory plugin is disabled.";
        }

        $parts = explode('|', $content, 2);

        if (count($parts) !== 2) {
            return "Error: Invalid format. Use [person delete]Name|factNumber[/person]";
        }

        $personName = trim($parts[0]);
        $factNumber = (int) trim($parts[1]);

        if (empty($personName)) {
            return "Error: Person name cannot be empty.";
        }

        if ($factNumber < 1) {
            return "Error: Fact number must be a positive integer.";
        }

        $result = $this->personMemoryService->deleteFact($preset, $personName, $factNumber);
        return $result['message'];
    }

    /**
     * Forget all facts about a person
     */
    public function forget(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Person memory plugin is disabled.";
        }

        $personName = trim($content);
        if (empty($personName)) {
            return "Error: Person name cannot be empty.";
        }

        $result = $this->personMemoryService->forgetPerson($preset, $personName);
        return $result['message'];
    }

    /**
     * List all people in memory
     */
    public function list(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return "Error: Person memory plugin is disabled.";
        }

        $result = $this->personMemoryService->listPeople($preset);
        return $result['message'];
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function pluginReady(AiPreset $preset): void
    {
        // No placeholders needed
    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['list'];
    }
}
