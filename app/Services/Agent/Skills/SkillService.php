<?php

namespace App\Services\Agent\Skills;

use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\AiPreset;
use App\Models\Skill;
use App\Models\SkillItem;
use Psr\Log\LoggerInterface;

class SkillService implements SkillServiceInterface
{
    public function __construct(
        protected TfIdfServiceInterface $tfIdfService,
        protected LoggerInterface $logger
    ) {
    }

    // -------------------------------------------------------------------------
    // SkillServiceInterface
    // -------------------------------------------------------------------------

    public function addSkill(
        AiPreset $preset,
        string $title,
        ?string $description = null,
        ?string $firstItem = null
    ): array {
        try {
            $number = $this->nextSkillNumber($preset);

            $skill = Skill::create([
                'preset_id'   => $preset->id,
                'number'      => $number,
                'title'       => $title,
                'description' => $description,
            ]);

            $msg = "Skill #{$number} created: {$title}";

            if ($firstItem !== null) {
                $this->createItem($skill, trim($firstItem));
                $msg .= " (item #1 added)";
            }

            return ['success' => true, 'message' => $msg, 'skill_number' => $number];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::addSkill error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error creating skill: " . $e->getMessage(), 'skill_number' => null];
        }
    }

    public function addItem(AiPreset $preset, int $skillNumber, string $content): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $item = $this->createItem($skill, $content);

            return [
                'success' => true,
                'message' => "Item #{$skillNumber}.{$item->number} added to skill \"{$skill->title}\".",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::addItem error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error adding item: " . $e->getMessage()];
        }
    }

    public function updateItem(AiPreset $preset, int $skillNumber, int $itemNumber, string $content): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $item = SkillItem::where('skill_id', $skill->id)
                             ->where('number', $itemNumber)
                             ->first();

            if ($item === null) {
                return ['success' => false, 'message' => "Item #{$skillNumber}.{$itemNumber} not found."];
            }

            $item->update([
                'content'      => $content,
                'tfidf_vector' => $this->tfIdfService->vectorize($content),
            ]);

            return [
                'success' => true,
                'message' => "Item #{$skillNumber}.{$itemNumber} updated.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::updateItem error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error updating item: " . $e->getMessage()];
        }
    }

    public function deleteItem(AiPreset $preset, int $skillNumber, int $itemNumber): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $deleted = SkillItem::where('skill_id', $skill->id)
                                ->where('number', $itemNumber)
                                ->delete();

            if (!$deleted) {
                return ['success' => false, 'message' => "Item #{$skillNumber}.{$itemNumber} not found."];
            }

            return [
                'success' => true,
                'message' => "Item #{$skillNumber}.{$itemNumber} deleted.",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::deleteItem error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error deleting item: " . $e->getMessage()];
        }
    }

    public function deleteSkill(AiPreset $preset, int $skillNumber): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $title = $skill->title;
            $skill->delete(); // cascades to items via FK

            return ['success' => true, 'message' => "Skill #{$skillNumber} \"{$title}\" deleted."];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::deleteSkill error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error deleting skill: " . $e->getMessage()];
        }
    }

    public function showSkill(AiPreset $preset, int $skillNumber): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber, withItems: true);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $lines = ["Skill #{$skill->number}: {$skill->title}"];

            if ($skill->description !== null) {
                $lines[] = "Description: {$skill->description}";
            }

            $items = $skill->items;

            if ($items->isEmpty()) {
                $lines[] = "(no items yet)";
            } else {
                $lines[] = "";
                foreach ($items as $item) {
                    $lines[] = "{$skill->number}.{$item->number}. {$item->content}";
                }
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::showSkill error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error showing skill: " . $e->getMessage()];
        }
    }

    public function listSkills(AiPreset $preset): array
    {
        try {
            $skills = Skill::where('preset_id', $preset->id)
                           ->withCount('items')
                           ->orderBy('number')
                           ->get();

            if ($skills->isEmpty()) {
                return ['success' => true, 'message' => 'No skills yet. Create one with [skill]title | first item[/skill]'];
            }

            $lines = ["Skills ({$skills->count()}):"];

            foreach ($skills as $skill) {
                $desc  = $skill->description ? " — {$skill->description}" : '';
                $count = $skill->items_count;
                $lines[] = "#{$skill->number} {$skill->title}{$desc} ({$count} " . ($count === 1 ? 'item' : 'items') . ")";
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::listSkills error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error listing skills: " . $e->getMessage()];
        }
    }

    public function searchItems(AiPreset $preset, string $query, int $limit = 5): array
    {
        try {
            $query = trim($query);
            if (empty($query)) {
                return ['success' => false, 'message' => 'Search query cannot be empty.'];
            }

            // Load all items for this preset (via JOIN)
            $items = SkillItem::whereHas('skill', fn ($q) => $q->where('preset_id', $preset->id))
                              ->with('skill')
                              ->get();

            if ($items->isEmpty()) {
                return ['success' => true, 'message' => 'No skill items to search yet.'];
            }

            $results = $this->tfIdfService->findSimilar($query, $items, $limit, 0.05, false);

            if (empty($results)) {
                return ['success' => true, 'message' => "No items found matching \"{$query}\"."];
            }

            $lines = ["Search results for \"{$query}\":"];

            foreach ($results as $result) {
                /** @var SkillItem $item */
                $item  = $result['document'];
                $score = round($result['similarity'] * 100, 1);
                $skill = $item->skill;
                $lines[] = "#{$skill->number}.{$item->number} [{$skill->title}] ({$score}%) {$item->content}";
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::searchItems error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error searching skills: " . $e->getMessage()];
        }
    }

    /**
     * Return skills as structured array for the admin UI.
     */
    public function listSkillsData(AiPreset $preset): array
    {
        return Skill::where('preset_id', $preset->id)
            ->withCount('items')
            ->orderBy('number')
            ->get()
            ->map(fn (Skill $s) => [
                'number'      => $s->number,
                'title'       => $s->title,
                'description' => $s->description,
                'items_count' => $s->items_count,
            ])
            ->toArray();
    }

    /**
     * Return skill with items as structured array for the admin UI.
     */
    public function showSkillData(AiPreset $preset, int $skillNumber): array
    {
        $skill = $this->findSkill($preset, $skillNumber, withItems: true);

        if ($skill === null) {
            return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
        }

        return [
            'success'     => true,
            'number'      => $skill->number,
            'title'       => $skill->title,
            'description' => $skill->description,
            'items'       => $skill->items->map(fn (SkillItem $i) => [
                'number'  => $i->number,
                'content' => $i->content,
            ])->toArray(),
        ];
    }

    /**
     * Return search results as structured array for the admin UI.
     */
    public function searchItemsData(AiPreset $preset, string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $items = SkillItem::whereHas('skill', fn ($q) => $q->where('preset_id', $preset->id))
                          ->with('skill')
                          ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $results = $this->tfIdfService->findSimilar($query, $items, $limit, 0.05, false);

        return collect($results)->map(function (array $r) {
            /** @var SkillItem $item */
            $item  = $r['document'];
            $skill = $item->skill;
            return [
                'skill_number'       => $skill->number,
                'skill_title'        => $skill->title,
                'item_number'        => $item->number,
                'content'            => $item->content,
                'similarity'         => $r['similarity'],
                'similarity_percent' => round($r['similarity'] * 100, 1),
            ];
        })->toArray();
    }

    public function getSkillsForContext(AiPreset $preset): string
    {
        $skills = Skill::where('preset_id', $preset->id)
                       ->withCount('items')
                       ->orderBy('number')
                       ->get();

        if ($skills->isEmpty()) {
            return '';
        }

        $parts = $skills->map(function (Skill $skill) {
            $desc  = $skill->description ? " ({$skill->description})" : '';
            $count = $skill->items_count;
            return "#{$skill->number} {$skill->title}{$desc} [{$count} " . ($count === 1 ? 'item' : 'items') . "]";
        });

        return "Skills: " . $parts->implode(' | ');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function findSkill(AiPreset $preset, int $number, bool $withItems = false): ?Skill
    {
        $query = Skill::where('preset_id', $preset->id)->where('number', $number);

        if ($withItems) {
            $query->with('items');
        }

        return $query->first();
    }

    private function nextSkillNumber(AiPreset $preset): int
    {
        $max = Skill::where('preset_id', $preset->id)->max('number');
        return ($max ?? 0) + 1;
    }

    private function nextItemNumber(Skill $skill): int
    {
        $max = SkillItem::where('skill_id', $skill->id)->max('number');
        return ($max ?? 0) + 1;
    }

    private function createItem(Skill $skill, string $content): SkillItem
    {
        $number = $this->nextItemNumber($skill);

        return SkillItem::create([
            'skill_id'     => $skill->id,
            'number'       => $number,
            'content'      => $content,
            'tfidf_vector' => $this->tfIdfService->vectorize($content),
        ]);
    }
}
