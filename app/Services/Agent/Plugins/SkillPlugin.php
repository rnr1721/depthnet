<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\Skills\SkillServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * SkillPlugin — persistent knowledge base with semantic search, plus the
 * lazy-skills navigation console.
 *
 * A skill is a named collection of knowledge items, indexed with TF-IDF so the
 * agent can search across all skills by meaning.
 *
 * ── Two operating modes (config: navigation_only) ────────────────────────────
 *   FULL (navigation_only = false, default): everything works as before — CRUD
 *   (create/add/update/delete/show/search) PLUS the navigation console
 *   (list/load/unload).
 *
 *   CONSOLE (navigation_only = true): CRUD is HIDDEN — in the schema/instructions
 *   AND refused in execution — leaving only list/load/unload. This is the mode the
 *   plugin runs in when knowledge is enabled for the preset: knowledge owns writing
 *   skill CONTENT (nature:skill), so SkillPlugin steps back to being a pure loading
 *   console. The flag is set by PluginManager when knowledge is toggled (see the
 *   exclusion wiring); the plugin only READS its own config — it never inspects
 *   whether knowledge is enabled, so it has no dependency on the knowledge plugin.
 *
 * ── Lazy-skills console verbs (both modes) ───────────────────────────────────
 *   [skill list][/skill]        — inventory: all skills, with a marker for loaded ones
 *   [skill load]N[/skill]       — load skill N: inject its content into context and,
 *                                 if it has tools, un-hide them in the schema
 *   [skill unload]N[/skill]     — unload skill N: drop it from context, re-hide tools
 *
 * load/unload do NOT touch SkillLoadService directly — they SIGNAL the requested
 * numbers via pluginExecutionMeta ('skill_load' / 'skill_unload', space-joined when
 * several fire in one cycle). AgentActionsHandler reads the meta and drives
 * SkillLoadService. This keeps the plugin free of the loading-state service and
 * matches how speak/handoff/turn already flow.
 *
 * ── CRUD verbs (FULL mode only) ──────────────────────────────────────────────
 *   [skill]title | first item[/skill], [skill add]N | item, [skill update]N.M | ...,
 *   [skill delete]N or N.M, [skill show]N, [skill search]query
 */
class SkillPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    /** Execution-meta keys the handler reads to drive SkillLoadService. */
    public const META_LOAD   = 'skill_load';
    public const META_UNLOAD = 'skill_unload';

    public function __construct(
        protected SkillServiceInterface $skillService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'skill';
    }

    public function getDescription(array $config = []): string
    {
        if ($this->isNavigationOnly($config)) {
            return 'Skill navigation console. Load a skill to bring its knowledge into '
                . 'context (and reveal its tools); unload it when done. Use "list" to see '
                . 'what skills exist and which are loaded.';
        }

        return 'Persistent knowledge base. Store reusable knowledge as named skills with items. '
            . 'Items are semantically searchable via TF-IDF. Load a skill to bring it into context.';
    }

    public function getInstructions(array $config = []): array
    {
        // Console verbs are available in BOTH modes.
        $navigation = [
            'List all skills (shows which are loaded): [skill list][/skill]',
            'Load a skill into context (reveals its tools if any): [skill load]1[/skill]',
            'Unload a skill when no longer needed: [skill unload]1[/skill]',
        ];

        if ($this->isNavigationOnly($config)) {
            // Console mode: navigation only. No CRUD — knowledge owns skill content.
            return $navigation;
        }

        // Full mode: CRUD + navigation.
        $crud = [
            'Create skill with first item: [skill]PostgreSQL | Use EXPLAIN ANALYZE to inspect query plans[/skill]',
            'Create empty skill: [skill]Code style[/skill]',
            'Add item to skill: [skill add]1 | Partial indexes speed up filtered queries significantly[/skill]',
            'Update item: [skill update]1.2 | Updated content here[/skill]',
            'Delete item: [skill delete]1.2[/skill]',
            'Delete entire skill: [skill delete]1[/skill]',
            'Show skill with all items: [skill show]1[/skill]',
            'Search items by meaning: [skill search]how to speed up slow queries[/skill]',
        ];

        $instructions = array_merge($crud, $navigation);

        $warning = $this->buildLanguageWarning($config, 'skill_language', 'skill entries');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * The method enum is mode-dependent: console mode exposes only list/load/unload,
     * so the model never sees (and never tries) a CRUD verb that would be refused.
     *
     * @return array OpenAI-compatible function descriptor (inner "function" object)
     */
    public function getToolSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'skill_language');

        if ($this->isNavigationOnly($config)) {
            return [
                'name'        => 'skill',
                'description' => 'Skill navigation console. Load a skill to bring its knowledge '
                    . 'into context and reveal its tools; unload when done; list to see what exists.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'method' => [
                            'type'        => 'string',
                            'description' => 'Operation to perform',
                            'enum'        => ['list', 'load', 'unload'],
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Argument depends on method.',
                                'load: the skill NUMBER or exact TITLE to bring into context (reveals its tools). '
                                    . 'Example: "1" or "Code". Empty loads the only skill if there is just one.',
                                'unload: the skill NUMBER or exact TITLE to drop from context. '
                                    . 'Example: "1" or "Code". Empty unloads the only loaded skill if there is just one.',
                                'list: leave empty.',
                            ]),
                        ],
                    ],
                    'required'   => ['method'],
                ],
            ];
        }

        return [
            'name'        => 'skill',
            'description' => 'Persistent knowledge base. '
                . 'Store reusable knowledge as named skills with items. '
                . 'Items are semantically searchable. '
                . 'Load a skill to bring it into context. '
                . $langInstruction . ' '
                . 'Use for stable, reusable knowledge you want to recall later — '
                . 'not for episodic events (use journal) or session state (use workspace).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['execute', 'add', 'update', 'delete', 'show', 'search', 'list', 'load', 'unload'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'execute (create skill): "title" or "title | first item content".',
                            'add (add item to skill): "skillNumber | item content".',
                            'update (update item): "skillNumber.itemNumber | new content".',
                            'delete: "skillNumber" or "skillNumber.itemNumber".',
                            'show: skill number to display all items.',
                            'search: natural language query to find relevant items.',
                            'load: REQUIRED — the skill NUMBER to bring into context (reveals its tools). Example: "1".',
                            'unload: REQUIRED — the skill NUMBER to drop from context. Example: "1".',
                            'list: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
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
            'navigation_only' => [
                'type'        => 'checkbox',
                'label'       => 'Navigation console only (no CRUD)',
                'description' => 'When on, only list/load/unload are available — skill content '
                    . 'is managed elsewhere (knowledge). Set automatically when knowledge is enabled.',
                'value'       => false,
                'required'    => false,
            ],
            'skill_language' => $this->getLanguageConfigField(
                'Skill Language',
                'Force language for skill entries. Model will be instructed accordingly.'
            ),
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

        if (isset($config['skill_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['skill_language'], $valid, true)) {
                $errors['skill_language'] = 'Invalid language selection.';
            }
        }

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
        return array_merge(
            [
                'enabled'         => false,
                'navigation_only' => false,
                'search_limit'    => 5,
            ],
            $this->getDefaultLanguageConfig('skill_language')
        );
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
    // Navigation console (both modes)
    // -------------------------------------------------------------------------

    /**
     * Load a skill into context by NUMBER or exact TITLE (case-insensitive).
     * Empty argument loads the only skill when the preset has exactly one.
     * Signals via execution meta; the handler drives SkillLoadService.
     */
    public function load(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Skill plugin is disabled.";
        }

        $number = $this->resolveSkillRef(trim($content), $context, forUnload: false);
        if ($number === null) {
            return $this->skillRefError($context, 'load', forUnload: false);
        }

        $result = $this->skillService->showSkill($context->preset, $number);
        if (!($result['success'] ?? false)) {
            return $result['message'];
        }

        $this->appendMeta(self::META_LOAD, (string) $number);

        return "Loaded skill #{$number} into context.\n\n" . $result['message'];
    }

    /**
     * Unload a skill from context by NUMBER or exact TITLE (case-insensitive).
     * Empty argument unloads the only skill when exactly one is currently loaded.
     * Symmetric with load(). Signals via execution meta.
     */
    public function unload(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Skill plugin is disabled.";
        }

        $number = $this->resolveSkillRef(trim($content), $context, forUnload: true);
        if ($number === null) {
            return $this->skillRefError($context, 'unload', forUnload: true);
        }

        $this->appendMeta(self::META_UNLOAD, (string) $number);

        return "Unloaded skill #{$number} from context.";
    }

    /**
     * List all skills (self-closing tag). Available in both modes.
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Skill plugin is disabled.";
        }

        $result = $this->skillService->listSkills($context->preset);
        return $result['message'];
    }

    // -------------------------------------------------------------------------
    // CRUD (FULL mode only — refused in console mode)
    // -------------------------------------------------------------------------

    /**
     * Default execute — create skill. Format: "title" or "title | first item".
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (($guard = $this->guardCrud($context)) !== null) {
            return $guard;
        }

        $parts = explode('|', $content, 2);
        $title = trim($parts[0]);

        if (empty($title)) {
            return "Error: Skill title cannot be empty. Use correct syntax";
        }

        $firstItem = isset($parts[1]) ? trim($parts[1]) : null;

        $result = $this->skillService->addSkill($context->preset, $title, null, $firstItem ?: null);
        return $result['message'];
    }

    /**
     * Add item to existing skill. Format: "skillNumber | item content"
     */
    public function add(string $content, PluginExecutionContext $context): string
    {
        if (($guard = $this->guardCrud($context)) !== null) {
            return $guard;
        }

        $parts = explode('|', $content, 2);

        if (count($parts) !== 2) {
            return "Error: Invalid format. Use correct syntax";
        }

        $skillNumber = (int) trim($parts[0]);
        $itemContent = trim($parts[1]);

        if (empty($itemContent)) {
            return "Error: Item content cannot be empty.";
        }

        $result = $this->skillService->addItem($context->preset, $skillNumber, $itemContent);
        return $result['message'];
    }

    /**
     * Update an item. Format: "skillNumber.itemNumber | new content"
     */
    public function update(string $content, PluginExecutionContext $context): string
    {
        if (($guard = $this->guardCrud($context)) !== null) {
            return $guard;
        }

        $parts = explode('|', $content, 2);

        if (count($parts) !== 2) {
            return "Error: Invalid format. Use correct syntax";
        }

        [$skillNumber, $itemNumber] = $this->parseItemRef(trim($parts[0]));

        if ($skillNumber === null || $itemNumber === null) {
            return "Error: Invalid item reference. Use format 1.2 (skill.item)";
        }

        $newContent = trim($parts[1]);

        if (empty($newContent)) {
            return "Error: Item content cannot be empty.";
        }

        $result = $this->skillService->updateItem($context->preset, $skillNumber, $itemNumber, $newContent);
        return $result['message'];
    }

    /**
     * Delete a skill or a single item.
     * "skillNumber" → delete whole skill; "skillNumber.itemNumber" → delete one item.
     */
    public function delete(string $content, PluginExecutionContext $context): string
    {
        if (($guard = $this->guardCrud($context)) !== null) {
            return $guard;
        }

        $ref = trim($content);

        if (str_contains($ref, '.')) {
            [$skillNumber, $itemNumber] = $this->parseItemRef($ref);

            if ($skillNumber === null || $itemNumber === null) {
                return "Error: Invalid reference. Use 1 to delete a skill or 1.2 to delete an item.";
            }

            $result = $this->skillService->deleteItem($context->preset, $skillNumber, $itemNumber);
        } else {
            $skillNumber = (int) $ref;
            $result      = $this->skillService->deleteSkill($context->preset, $skillNumber);
        }

        return $result['message'];
    }

    /**
     * Show full skill with all items. Format: "skillNumber"
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (($guard = $this->guardCrud($context)) !== null) {
            return $guard;
        }

        $skillNumber = (int) trim($content);
        $result      = $this->skillService->showSkill($context->preset, $skillNumber);
        return $result['message'];
    }

    /**
     * Semantic search across all skill items. Format: "query text"
     */
    public function search(string $content, PluginExecutionContext $context): string
    {
        if (($guard = $this->guardCrud($context)) !== null) {
            return $guard;
        }

        $query = trim($content);

        if (empty($query)) {
            return "Error: Search query cannot be empty.";
        }

        $limit = $context->get('search_limit', 5);
        $result = $this->skillService->searchItems($context->preset, $query, $limit);
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

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $preset = $context->preset;

        $this->placeholderService->registerDynamic(
            'skills',
            'Inventory of skills with load status and hidden-tool hints',
            function () use ($preset) {
                $skillLoad = app(\App\Services\Agent\Skills\SkillLoadService::class); // ленивый резолв
                return $this->skillService->getSkillsForContext(
                    $preset,
                    $skillLoad->loadedSkillNumbers($preset),
                    $skillLoad->livePluginNames()
                );
            },
            $scope,
            false,
            $this->getName()
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the plugin is in console (navigation-only) mode for this config.
     */
    private function isNavigationOnly(array $config): bool
    {
        return (bool) ($config['navigation_only'] ?? false);
    }

    /**
     * Guard a CRUD method: returns an error string when the plugin is disabled or
     * in console mode, or null when the method may proceed.
     *
     * In console mode CRUD is refused with a pointer to knowledge, rather than a
     * generic error — the model shouldn't even see these verbs (the schema hides
     * them), but a stray tag still gets a coherent answer instead of junk.
     */
    private function guardCrud(PluginExecutionContext $context): ?string
    {
        if (!$context->enabled) {
            return "Error: Skill plugin is disabled.";
        }

        if ($this->isNavigationOnly($context->config)) {
            return "Skill content is managed through knowledge here. Use knowledge to "
                . "remember/recall skills; use [skill list], [skill load]N and [skill unload]N "
                . "to bring them into context.";
        }

        return null;
    }

    /**
     * Append a value to an execution-meta key as a space-joined string, so several
     * load/unload calls in one cycle all survive CommandExecutor's meta merge
     * (which concatenates string values with a space). setPluginExecutionMeta
     * overwrites, so we accumulate here across calls within this plugin instance.
     */
    private function appendMeta(string $key, string $value): void
    {
        $existing = $this->pluginExecutionMeta[$key] ?? '';
        $existing = is_string($existing) ? trim($existing) : '';

        $merged = $existing === '' ? $value : $existing . ' ' . $value;

        $this->setPluginExecutionMeta($key, $merged);
    }

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

    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }

    /**
     * Resolve a load/unload argument to a skill number. Accepts:
     *   - empty → the sole unambiguous candidate:
     *       load  : the only skill in the preset (exactly one exists)
     *       unload: the only currently-loaded skill (exactly one loaded)
     *   - a numeric string → that skill number (verified to exist)
     *   - a title → case-insensitive exact match, must be unique
     * Returns null when it cannot decide (none, unknown, ambiguous, or empty with
     * several candidates). forUnload switches the empty-arg candidate set to the
     * loaded skills, so the "don't make me choose when there's one" rule is applied
     * to the set each verb actually operates on — symmetric, not identical sets.
     */
    private function resolveSkillRef(string $ref, PluginExecutionContext $context, bool $forUnload): ?int
    {
        $all = $this->skillService->listSkillsData($context->preset);
        if (empty($all)) {
            return null;
        }

        // Empty argument → the unambiguous candidate for this verb.
        if ($ref === '') {
            if ($forUnload) {
                $loaded = app(\App\Services\Agent\Skills\SkillLoadService::class)
                    ->loadedSkillNumbers($context->preset);
                return count($loaded) === 1 ? (int) $loaded[0] : null;
            }
            return count($all) === 1 ? (int) $all[0]['number'] : null;
        }

        // Pure number → a skill number (verify it exists).
        if (ctype_digit($ref)) {
            $n = (int) $ref;
            foreach ($all as $s) {
                if ((int) $s['number'] === $n) {
                    return $n;
                }
            }
            return null;
        }

        // Otherwise a title: case-insensitive exact match, must be unique.
        $needle  = mb_strtolower($ref);
        $matches = [];
        foreach ($all as $s) {
            if (mb_strtolower((string) $s['title']) === $needle) {
                $matches[] = (int) $s['number'];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Build a helpful "couldn't resolve" message that LISTS what's available, so a
     * weaker model sees the valid options immediately instead of only being told a
     * number is needed.
     */
    private function skillRefError(PluginExecutionContext $context, string $verb, bool $forUnload): string
    {
        $all = $this->skillService->listSkillsData($context->preset);
        if (empty($all)) {
            return "No skills exist yet in this preset.";
        }

        if ($forUnload) {
            $loaded = app(\App\Services\Agent\Skills\SkillLoadService::class)
                ->loadedSkillNumbers($context->preset);
            if (empty($loaded)) {
                return "No skills are currently loaded, so there is nothing to unload.";
            }
            $byNumber = [];
            foreach ($all as $s) {
                $byNumber[(int) $s['number']] = $s['title'];
            }
            $avail = implode(', ', array_map(
                fn ($n) => "#{$n} " . ($byNumber[$n] ?? ''),
                $loaded
            ));
            return "Could not resolve which skill to unload. Pass a number or exact title. "
                . "Currently loaded: {$avail}.";
        }

        $avail = implode(', ', array_map(
            fn ($s) => "#{$s['number']} {$s['title']}",
            $all
        ));
        return "Could not resolve which skill to load. Pass a number or exact title. "
            . "Available: {$avail}.";
    }

}
