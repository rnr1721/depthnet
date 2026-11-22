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
        ?array $tools = null,
        bool $isExperience = false
    ): array {
        try {
            $tools = $this->normalizeTools($tools);
            if ($isExperience && $tools !== null) {
                return ['success' => false, 'message' => self::MSG_EXPERIENCE_TOOLS, 'skill_number' => null];
            }

            $number = $this->nextSkillNumber($preset);

            $skill = $this->skillModel->create([
                'preset_id'     => $preset->id,
                'number'        => $number,
                'title'         => $title,
                'description'   => $description,
                'tools'         => $tools,
                'source'        => Skill::SOURCE_DB,
                'is_experience' => $isExperience,
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
            if (array_key_exists('is_experience', $fields) && $fields['is_experience'] !== null) {
                $update['is_experience'] = (bool) $fields['is_experience'];
            }

            // Guest skill: title/description mirror SKILL.md. A form that re-sends the
            // unchanged values is fine; an actual change is refused.
            if ($skill->isContentReadOnly()) {
                foreach (['title', 'description'] as $key) {
                    if (array_key_exists($key, $update) && $update[$key] !== $skill->{$key}) {
                        return ['success' => false, 'message' => $this->readOnlyMessage($skill)];
                    }
                    unset($update[$key]);
                }
            }

            // tools and is_experience are mutually exclusive (checked on the RESULTING state).
            $finalTools = array_key_exists('tools', $update) ? $update['tools'] : ($skill->getToolNames() ?: null);
            $finalExp   = $update['is_experience'] ?? $skill->isExperience();
            if ($finalExp && !empty($finalTools)) {
                return ['success' => false, 'message' => self::MSG_EXPERIENCE_TOOLS];
            }
            if ($finalExp && $skill->hasScripts()) {
                return ['success' => false, 'message' => "Skill #{$skillNumber} ships scripts; it must be loaded "
                    . 'to run them, so it cannot be an experience skill (RAG would surface it without its files).'];
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
            if ($skill->isContentReadOnly()) {
                return ['success' => false, 'message' => $this->readOnlyMessage($skill)];
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
            if ($skill->isContentReadOnly()) {
                return ['success' => false, 'message' => $this->readOnlyMessage($skill)];
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
            if ($skill->isContentReadOnly()) {
                return ['success' => false, 'message' => $this->readOnlyMessage($skill)];
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

            $title    = $skill->title;
            $fromFile = $skill->isFromFile();
            $path     = $skill->source_path;
            $skill->delete(); // cascades to items via FK

            if ($fromFile) {
                return ['success' => true, 'message' => "Skill #{$skillNumber} \"{$title}\" detached "
                    . "(library folder \"{$path}\" is kept and can be connected again)."];
            }

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
            if ($skill->isFromFile()) {
                $lines[] = "Source: standard skill \"{$skill->source_path}\" from the library (read-only)";
                if ($skill->hasBundledFiles()) {
                    $lines[] = $this->bundledFilesLine($skill);
                }
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
                return ['success' => true, 'message' => 'No skills yet. Create one with `skill execute <title> | <first item>`.'];
            }

            $lines = ["Skills ({$skills->count()}):"];

            foreach ($skills as $skill) {
                $desc  = $skill->description ? " — {$skill->description}" : '';
                $count = $skill->items_count;
                $tag   = $skill->isFromFile() ? ' [standard, read-only]' : '';
                $line  = "#{$skill->number} {$skill->title}{$tag}{$desc} ({$count} " . ($count === 1 ? 'item' : 'items') . ")";
                if ($skill->hasBundledFiles()) {
                    $line .= " + files → `skill read {$skill->source_path}`";
                }
                $lines[] = $line;
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
            ] + $this->provenanceData($s))
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
        ] + $this->provenanceData($skill, detailed: true);
    }

    /**
     * @inheritDoc
     */
    public function searchItemsData(
        AiPreset $preset,
        string $query,
        int $limit = 5,
        bool $includeFileSkills = true,
        bool $experienceOnly = false
    ): array {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $items = $this->skillItemModel->whereHas('skill', function ($q) use ($preset, $includeFileSkills, $experienceOnly) {
            $q->where('preset_id', $preset->id);
            if (!$includeFileSkills) {
                $q->where('source', '!=', Skill::SOURCE_FILE);
            }
            if ($experienceOnly) {
                $q->where('is_experience', true);
            }
        })
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
        array $livePluginNames = [],
        array $untouchedCycles = []
    ): string {
        $skills = $this->skillModel->where('preset_id', $preset->id)
                       ->withCount('items')
                       ->orderBy('number')
                       ->get();

        if ($skills->isEmpty()) {
            return '';
        }

        $tokens = $this->approxTokensBySkill($skills->pluck('id')->all());

        $loadedSet = array_fill_keys(array_map('intval', $loadedNumbers), true);
        $filterLive = !empty($livePluginNames);
        $liveSet    = array_fill_keys($livePluginNames, true);

        $lines = ['[SKILLS]'];
        $lines[] = 'My skills. A skill can carry tools that stay HIDDEN until I load it. '
                 . 'Load a skill to bring its knowledge into context and reveal its tools; '
                 . 'unload when done. Loading is explicit: `skill load N` / `skill unload N` '
                 . '(the skill tool with method load/unload and the number as content).';

        foreach ($skills as $skill) {
            $isLoaded = isset($loadedSet[(int) $skill->number]);

            // Tools to display: the skill's own, filtered to live plugins.
            $tools = $skill->getToolNames();
            if ($filterLive) {
                $tools = array_values(array_filter($tools, fn ($t) => isset($liveSet[$t])));
            }

            $desc   = $skill->description ? " — {$skill->description}" : '';
            $count  = $skill->items_count;
            $size   = $this->formatTokens($tokens[$skill->id] ?? 0);
            $items  = "({$count} " . ($count === 1 ? 'item' : 'items') . ($size !== '' ? ", {$size}" : '') . ")";
            $status = $isLoaded ? '● loaded' : 'not loaded';
            if ($isLoaded && isset($untouchedCycles[(int) $skill->number])) {
                $status .= ', ' . $this->formatUntouched((int) $untouchedCycles[(int) $skill->number]);
            }

            $line = "#{$skill->number} {$skill->title}{$desc} {$items} — {$status}";

            // Standard skill shipping files: name what it ships, so a task that says
            // "scripts/x.py" or "forms.md" connects to THIS skill instead of a disk search.
            if ($skill->hasBundledFiles()) {
                $layout = $this->filesLayout($skill);
                $shown  = array_slice($layout, 0, 6);
                $what   = $shown !== [] ? implode(', ', $shown) . (count($layout) > 6 ? ', …' : '') : 'extra files';
                $line .= "; ships {$what} → `skill read {$skill->source_path}`";
            }

            if (!empty($tools)) {
                $toolList = implode(', ', $tools);
                if ($isLoaded) {
                    $line .= "; tools active: {$toolList}";
                } else {
                    $line .= "; tools (hidden): {$toolList} → `skill load {$skill->number}` to use";
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

    private const MSG_EXPERIENCE_TOOLS = 'A skill cannot both carry tools and be an experience skill: '
        . 'tools are revealed by loading, experience reaches context through RAG. Clear one of them first.';

    /**
     * Past-tense snapshot of the loaded skills for a compaction recap, or '' when
     * nothing is loaded. Deterministic (no LLM): facts only, the decision stays
     * with the agent.
     *
     * @param int[]          $loadedNumbers
     * @param array<int,int> $untouchedCycles
     */
    public function loadedSkillsSnapshot(AiPreset $preset, array $loadedNumbers, array $untouchedCycles = []): string
    {
        if ($loadedNumbers === []) {
            return '';
        }

        $skills = $this->skillModel->where('preset_id', $preset->id)
            ->whereIn('number', $loadedNumbers)
            ->orderBy('number')
            ->get();
        if ($skills->isEmpty()) {
            return '';
        }

        $tokens = $this->approxTokensBySkill($skills->pluck('id')->all());
        $parts  = [];
        $total  = 0;
        foreach ($skills as $s) {
            $t = $tokens[$s->id] ?? 0;
            $total += $t;
            $facts = array_filter([
                $this->formatTokens($t),
                isset($untouchedCycles[(int) $s->number]) ? $this->formatUntouched((int) $untouchedCycles[(int) $s->number]) : null,
            ]);
            $parts[] = "#{$s->number} {$s->title}" . ($facts ? ' (' . implode(', ', $facts) . ')' : '');
        }

        return 'At the moment of folding, these skills were loaded: ' . implode('; ', $parts)
            . ($total > 0 ? ' — together ' . $this->formatTokens($total) . ' on the desk every cycle' : '')
            . '. Unload what the next work will not need (`skill unload N`).';
    }

    /**
     * Rough token estimate per skill: item characters / 4. Good enough for "is this
     * skill big?"; not a billing number.
     *
     * @param int[] $skillIds
     * @return array<int,int>  skill_id => tokens
     */
    private function approxTokensBySkill(array $skillIds): array
    {
        if ($skillIds === []) {
            return [];
        }
        try {
            $len = $this->skillItemModel->getConnection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
            return $this->skillItemModel->newQuery()
                ->whereIn('skill_id', $skillIds)
                ->groupBy('skill_id')
                ->selectRaw("skill_id, SUM({$len}(content)) AS chars")
                ->pluck('chars', 'skill_id')
                ->map(fn ($c) => (int) ceil(((int) $c) / 4))
                ->all();
        } catch (\Throwable $e) {
            $this->logger->warning('SkillService: token estimate failed: ' . $e->getMessage());
            return [];
        }
    }

    private function formatTokens(int $tokens): string
    {
        if ($tokens <= 0) {
            return '';
        }
        return $tokens >= 1000 ? '~' . round($tokens / 1000, 1) . 'k tokens' : "~{$tokens} tokens";
    }

    private function formatUntouched(int $cycles): string
    {
        return match (true) {
            $cycles === 0 => 'used last cycle',
            $cycles === 1 => 'unused 1 cycle',
            default       => "unused {$cycles} cycles",
        };
    }

    /**
     * "Files (12): LICENSE.txt, forms.md, reference.md, scripts/ (8) — …" for the
     * load/show output. Paths named in SKILL.md (FORMS.md, scripts/x.py) are relative
     * to this folder, and the agent must learn that here, or it goes hunting the disk.
     */
    private function bundledFilesLine(Skill $skill): string
    {
        $name = $skill->source_path;
        $hint = "read with `skill read {$name} <path>`; paths in the text above are relative to this skill.";

        $parts = $this->filesLayout($skill);
        if ($parts === []) {
            return "Files: shipped with the skill — {$hint}";
        }
        if (count($parts) > 15) {
            $parts = array_merge(array_slice($parts, 0, 15), ['…']);
        }

        return 'Files: ' . implode(', ', $parts) . " — {$hint}";
    }

    /**
     * Top-level layout of a guest skill folder. Stored at import (source_meta.layout);
     * skills imported before that field existed fall back to a live listing.
     *
     * @return string[]
     */
    private function filesLayout(Skill $skill): array
    {
        $layout = $skill->source_meta['layout'] ?? null;
        if (is_array($layout)) {
            return array_values(array_filter($layout, 'is_string'));
        }

        try {
            $files = app(\App\Contracts\Agent\Skills\SkillLibraryServiceInterface::class)->listFiles($skill);
        } catch (\Throwable $e) {
            $files = null;
        }
        return $files ? SkillLibraryService::summarizeLayout(array_keys($files)) : [];
    }

    private function readOnlyMessage(Skill $skill): string
    {
        return "Skill #{$skill->number} is a standard skill imported from the library "
            . "(\"{$skill->source_path}\"); its title, description and items mirror SKILL.md and are read-only. "
            . 'Edit the file in the library and refresh, or create your own skill. Tools and the experience flag stay editable.';
    }

    /**
     * Provenance fields for the admin UI. `detailed` adds the SKILL.md frontmatter
     * extras (license, compatibility, allowed-tools verbatim, metadata).
     */
    private function provenanceData(Skill $s, bool $detailed = false): array
    {
        $data = [
            'source'        => $s->source ?? Skill::SOURCE_DB,
            'source_path'   => $s->source_path,
            'is_experience' => $s->isExperience(),
            'read_only'     => $s->isContentReadOnly(),
            'has_files'     => $s->hasBundledFiles(),
            'has_scripts'   => $s->hasScripts(),
        ];

        if ($detailed && $s->isFromFile()) {
            $meta = $s->source_meta ?? [];
            $data['source_meta'] = [
                'license'       => $meta['license'] ?? null,
                'compatibility' => $meta['compatibility'] ?? null,
                'allowed_tools' => $meta['allowed_tools'] ?? null,
                'metadata'      => $meta['metadata'] ?? [],
                'warnings'      => $meta['warnings'] ?? [],
                'file_count'    => $meta['file_count'] ?? null,
                'imported_at'   => $meta['imported_at'] ?? null,
            ];
        }

        return $data;
    }

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
