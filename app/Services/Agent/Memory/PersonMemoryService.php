<?php

namespace App\Services\Agent\Memory;

use App\Contracts\Agent\Memory\PersonMemoryServiceInterface;
use App\Models\AiPreset;
use App\Models\PersonMemory;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Service for managing person-specific memory facts.
 * Each person has their own numbered list of facts that can be
 * added, deleted, and recalled independently.
 */
class PersonMemoryService implements PersonMemoryServiceInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PersonMemory $personMemoryModel
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addFact(AiPreset $preset, string $personName, string $content): array
    {
        try {
            $personName = $this->normalizeName($personName);
            $content = trim($content);

            if (empty($content)) {
                return [
                    'success' => false,
                    'message' => 'Error: Fact content cannot be empty.'
                ];
            }

            $currentCount = $this->personMemoryModel
                ->forPreset($preset->id)
                ->forPerson($personName)
                ->count();

            $newPosition = $currentCount + 1;

            $this->personMemoryModel->create([
                'preset_id' => $preset->id,
                'person_name' => $personName,
                'content' => $content,
                'position' => $newPosition
            ]);

            return [
                'success' => true,
                'message' => "Fact [{$newPosition}] about {$personName} saved."
            ];

        } catch (\Throwable $e) {
            $this->logger->error("PersonMemoryService::addFact error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error saving fact: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function recallPerson(AiPreset $preset, string $personName): array
    {
        try {
            $personName = $this->normalizeName($personName);
            $items = $this->getPersonFacts($preset, $personName);

            if ($items->isEmpty()) {
                return [
                    'success' => true,
                    'message' => "No facts stored about {$personName} yet."
                ];
            }

            $formatted = $this->formatFacts($personName, $items);

            return [
                'success' => true,
                'message' => $formatted
            ];

        } catch (\Throwable $e) {
            $this->logger->error("PersonMemoryService::recallPerson error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error recalling person: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteFact(AiPreset $preset, string $personName, int $factNumber): array
    {
        try {
            $personName = $this->normalizeName($personName);
            $items = $this->getPersonFacts($preset, $personName);

            if ($factNumber < 1 || $factNumber > $items->count()) {
                return [
                    'success' => false,
                    'message' => "Error: Fact [{$factNumber}] does not exist. {$personName} has {$items->count()} facts."
                ];
            }

            $itemToDelete = $items[$factNumber - 1];
            $itemToDelete->delete();

            $this->reorderPersonFacts($preset, $personName);

            $remaining = $items->count() - 1;
            $message = $remaining === 0
                ? "Fact [{$factNumber}] about {$personName} deleted. No facts remain."
                : "Fact [{$factNumber}] about {$personName} deleted. {$remaining} facts remain.";

            return [
                'success' => true,
                'message' => $message
            ];

        } catch (\Throwable $e) {
            $this->logger->error("PersonMemoryService::deleteFact error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error deleting fact: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function listPeople(AiPreset $preset): array
    {
        try {
            $people = $this->personMemoryModel
                ->forPreset($preset->id)
                ->select('person_name')
                ->selectRaw('COUNT(*) as fact_count')
                ->groupBy('person_name')
                ->orderBy('person_name')
                ->get();

            if ($people->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'No people in memory yet.'
                ];
            }

            $lines = ['People in memory:'];
            foreach ($people as $person) {
                $lines[] = "- {$person->person_name} ({$person->fact_count} facts)";
            }

            return [
                'success' => true,
                'message' => implode("\n", $lines)
            ];

        } catch (\Throwable $e) {
            $this->logger->error("PersonMemoryService::listPeople error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error listing people: " . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function forgetPerson(AiPreset $preset, string $personName): array
    {
        try {
            $personName = $this->normalizeName($personName);

            $count = $this->personMemoryModel
                ->forPreset($preset->id)
                ->forPerson($personName)
                ->count();

            if ($count === 0) {
                return [
                    'success' => false,
                    'message' => "No facts found about {$personName}."
                ];
            }

            $this->personMemoryModel
                ->forPreset($preset->id)
                ->forPerson($personName)
                ->delete();

            return [
                'success' => true,
                'message' => "All {$count} facts about {$personName} forgotten."
            ];

        } catch (\Throwable $e) {
            $this->logger->error("PersonMemoryService::forgetPerson error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error forgetting person: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get facts for a person as a Collection
     *
     * @param AiPreset $preset
     * @param string $personName
     * @return Collection
     */
    protected function getPersonFacts(AiPreset $preset, string $personName): Collection
    {
        return $this->personMemoryModel
            ->forPreset($preset->id)
            ->forPerson($personName)
            ->ordered()
            ->get();
    }

    /**
     * Format facts for output
     *
     * @param string $personName
     * @param Collection $items
     * @return string
     */
    protected function formatFacts(string $personName, Collection $items): string
    {
        $lines = ["Person: {$personName}"];
        foreach ($items as $index => $item) {
            $number = $index + 1;
            $lines[] = "[{$number}] {$item->content}";
        }
        return implode("\n", $lines);
    }

    /**
     * Reorder facts for a person to have sequential positions
     *
     * @param AiPreset $preset
     * @param string $personName
     * @return void
     */
    protected function reorderPersonFacts(AiPreset $preset, string $personName): void
    {
        $items = $this->personMemoryModel
            ->forPreset($preset->id)
            ->forPerson($personName)
            ->ordered()
            ->get();

        foreach ($items as $index => $item) {
            $newPosition = $index + 1;
            if ($item->position !== $newPosition) {
                $item->update(['position' => $newPosition]);
            }
        }
    }

    /**
     * Normalize person name for consistent storage
     *
     * @param string $name
     * @return string
     */
    protected function normalizeName(string $name): string
    {
        return trim($name);
    }
}
