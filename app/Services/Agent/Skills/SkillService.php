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
        protected Skill $skillModel,
        protected SkillItem $skillItemModel,
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
        ?string $firstItem = null,
        ?array $tools = null
    ): array {
        try {
            $number = $this->nextSkillNumber($preset);

            $skill = $this->skillModel->create([
                'preset_id'   => $preset->id,
                'number'      => $number,
                'title'       => $title,
                'description' => $description,
                'tools'       => $this->normalizeTools($tools),
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

    /**
     * Update a skill's own fields (title/description/tools) — NOT its items.
     * Only provided keys are changed. tools: pass an array to set, or omit to leave.
     */
    public function updateSkill(
        AiPreset $preset,
        int $skillNumber,
        array $fields
    ): array {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $update = [];
            if (array_key_exists('title', $fields) && trim((string) $fields['title']) !== '') {
                $update['title'] = trim((string) $fields['title']);
            }
            if (array_key_exists('description', $fields)) {
                $desc = trim((string) $fields['description']);
                $update['description'] = $desc !== '' ? $desc : null;
            }
            if (array_key_exists('tools', $fields)) {
                $update['tools'] = $this->normalizeTools($fields['tools']);
            }

            if (!empty($update)) {
                $skill->update($update);
            }

            return ['success' => true, 'message' => "Skill #{$skillNumber} updated."];
        } catch (\Throwable $e) {
            $this->logger->error("SkillService::updateSkill error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error updating skill: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
    public function updateItem(AiPreset $preset, int $skillNumber, int $itemNumber, string $content): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $item = $this->skillItemModel->where('skill_id', $skill->id)
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

    /**
     * @inheritDoc
     */
    public function deleteItem(AiPreset $preset, int $skillNumber, int $itemNumber): array
    {
        try {
            $skill = $this->findSkill($preset, $skillNumber);
            if ($skill === null) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} not found."];
            }

            $deleted = $this->skillItemModel->where('skill_id', $skill->id)
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

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
    public function listSkills(AiPreset $preset): array
    {
        try {
            $skills = $this->skillModel->where('preset_id', $preset->id)
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

    /**
     * @inheritDoc
     */
    public function searchItems(AiPreset $preset, string $query, int $limit = 5): array
    {
        try {
            $query = trim($query);
            if (empty($query)) {
                return ['success' => false, 'message' => 'Search query cannot be empty.'];
            }

            // Load all items for this preset (via JOIN)
            $items = $this->skillItemModel->whereHas('skill', fn ($q) => $q->where('preset_id', $preset->id))
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
     * @inheritDoc
     */
    public function listSkillsData(AiPreset $preset): array
    {
        return $this->skillModel->where('preset_id', $preset->id)
            ->withCount('items')
            ->orderBy('number')
            ->get()
            ->map(fn (Skill $s) => [
                'number'      => $s->number,
                'title'       => $s->title,
                'description' => $s->description,
                'tools'       => $s->getToolNames(),
                'items_count' => $s->items_count,
            ])
            ->toArray();
    }

    /**
     * @inheritDoc
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
            'tools'       => $skill->getToolNames(),
            'items'       => $skill->items->map(fn (SkillItem $i) => [
                'number'  => $i->number,
                'content' => $i->content,
            ])->toArray(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function searchItemsData(AiPreset $preset, string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $items = $this->skillItemModel->whereHas('skill', fn ($q) => $q->where('preset_id', $preset->id))
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

    /**
     * @inheritDoc
     */
    public function getSkillsForContext(
        AiPreset $preset,
        array $loadedNumbers = [],
        array $livePluginNames = []
    ): string {
        $skills = $this->skillModel->where('preset_id', $preset->id)
                       ->withCount('items')
                       ->orderBy('number')
                       ->get();

        if ($skills->isEmpty()) {
            return '';
        }

        $loadedSet = array_fill_keys(array_map('intval', $loadedNumbers), true);
        $filterLive = !empty($livePluginNames);
        $liveSet    = array_fill_keys($livePluginNames, true);

        $lines = ['[SKILLS]'];
        $lines[] = 'My skills. A skill can carry tools that stay HIDDEN until I load it. '
                 . 'Load a skill to bring its knowledge into context and reveal its tools; '
                 . 'unload when done. Loading is explicit: [skill load]N / [skill unload]N.';

        foreach ($skills as $skill) {
            $isLoaded = isset($loadedSet[(int) $skill->number]);

            // Tools to display: the skill's own, filtered to live plugins.
            $tools = $skill->getToolNames();
            if ($filterLive) {
                $tools = array_values(array_filter($tools, fn ($t) => isset($liveSet[$t])));
            }

            $desc   = $skill->description ? " — {$skill->description}" : '';
            $count  = $skill->items_count;
            $items  = "({$count} " . ($count === 1 ? 'item' : 'items') . ")";
            $status = $isLoaded ? '● loaded' : 'not loaded';

            $line = "#{$skill->number} {$skill->title}{$desc} {$items} — {$status}";

            if (!empty($tools)) {
                $toolList = implode(', ', $tools);
                if ($isLoaded) {
                    $line .= "; tools active: {$toolList}";
                } else {
                    $line .= "; tools (hidden): {$toolList} → [skill load]{$skill->number} to use";
                }
            }

            $lines[] = $line;
        }

        $lines[] = '[/SKILLS]';

        return implode("\n", $lines);
    }

    /**
     * @inheritDoc
     */
    public function deleteAllSkills(AiPreset $preset): array
    {
        try {
            $count = $this->skillModel->where('preset_id', $preset->id)->count();

            if ($count === 0) {
                return [
                    'success' => true,
                    'message' => 'No skills to delete.',
                ];
            }

            $this->skillModel->where('preset_id', $preset->id)->delete(); // items удалятся по cascade

            return [
                'success' => true,
                'message' => "Deleted {$count} " . ($count === 1 ? 'skill' : 'skills') . ".",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("SkillService::deleteAllSkills error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => "Error deleting all skills: " . $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find a skill by its number within a preset.
     *
     * Optionally loads related items.
     *
     * @param AiPreset $preset
     * @param int $number Skill number (not ID)
     * @param bool $withItems Whether to eager load items
     * @return Skill|null
     */
    private function findSkill(AiPreset $preset, int $number, bool $withItems = false): ?Skill
    {
        $query = $this->skillModel->where('preset_id', $preset->id)->where('number', $number);

        if ($withItems) {
            $query->with('items');
        }

        return $query->first();
    }

    /**
     * Get the next sequential skill number for a preset.
     *
     * @param AiPreset $preset
     * @return int
     */
    private function nextSkillNumber(AiPreset $preset): int
    {
        $max = $this->skillModel->where('preset_id', $preset->id)->max('number');
        return ($max ?? 0) + 1;
    }

    /**
     * Get the next sequential item number within a skill.
     *
     * @param Skill $skill
     * @return int
     */
    private function nextItemNumber(Skill $skill): int
    {
        $max = $this->skillItemModel->where('skill_id', $skill->id)->max('number');
        return ($max ?? 0) + 1;
    }

    /**
     * Create a new skill item with TF-IDF vectorization.
     *
     * @param Skill $skill
     * @param string $content
     * @return SkillItem
     */
    private function createItem(Skill $skill, string $content): SkillItem
    {
        $number = $this->nextItemNumber($skill);

        return $this->skillItemModel->create([
            'skill_id'     => $skill->id,
            'number'       => $number,
            'content'      => $content,
            'tfidf_vector' => $this->tfIdfService->vectorize($content),
        ]);
    }

    /**
     * Normalize a tools input into a clean string[] or null.
     * Accepts an array (from JSON) or null. Empty → null (pure-knowledge skill).
     * Dedupes, trims, drops empties. Does NOT validate against the plugin registry —
     * unknown names are kept (may be future custom tools) and simply ignored at read
     * time by SkillLoadService's live-registry intersection.
     */
    private function normalizeTools(?array $tools): ?array
    {
        if (empty($tools)) {
            return null;
        }
        $clean = [];
        foreach ($tools as $t) {
            $t = is_string($t) ? trim($t) : '';
            if ($t !== '') {
                $clean[$t] = true;
            }
        }
        return $clean === [] ? null : array_keys($clean);
    }

}
