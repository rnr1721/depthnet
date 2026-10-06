<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginHasLanguageSettingsTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * GoalPlugin - persistent goal tracking with progress history and focus.
 *
 * Active goals are always visible in Dynamic Context ([[active_goals]]) — the
 * agent's intentions. One goal can be put IN FOCUS — what the agent is doing
 * right now: its full progress history is injected into the cycle context as
 * desktop material (ContextInjectionService), and it is listed first and marked.
 *
 * While a goal is in focus, the goal number may be omitted in progress / done /
 * pause / drop / show — the focused goal is the default target.
 *
 * Commands:
 *   [goal]title | motivation: why this matters[/goal]   — create goal
 *   [goal focus]3[/goal]                                — put goal 3 in focus (releases the previous one)
 *   [goal unfocus][/goal]                               — release focus, goal stays active
 *   [goal progress]what I just figured out[/goal]       — note on the focused goal
 *   [goal progress]1 | what I just figured out[/goal]   — note on goal 1
 *   [goal done][/goal]   / [goal done]1[/goal]          — achieved (releases focus)
 *   [goal pause][/goal]  / [goal pause]1[/goal]         — defer, not now (releases focus)
 *   [goal drop][/goal]   / [goal drop]1[/goal]          — abandon on purpose (releases focus)
 *   [goal resume]1[/goal]                               — resume paused goal
 *   [goal show][/goal]   / [goal show]1[/goal]          — full detail with history
 *   [goal list][/goal]                                  — active goals
 *   [goal list]all[/goal]                               — all goals
 */
class GoalPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;
    use PluginHasLanguageSettingsTrait;

    public function __construct(
        protected GoalServiceInterface $goalService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'goal';
    }

    public function getDescription(array $config = []): string
    {
        return 'Persistent goal tracking with progress history. Active goals are always visible in context. '
            . 'Your conversation history is short and older messages drop out of it; a goal in focus does not — '
            . 'its whole progress history stays in front of you every cycle. Use it to keep anything that spans many cycles.';
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [
            'Why focus: your conversation history is short — instructions, plans and results from a few cycles ago drop out of it. '
                . 'The goal in focus does not: its title, motivation and progress notes are in front of you every cycle.',
            'When you take on anything that will span several cycles (a multi-step task, a request with a list of steps, '
                . 'an exploration you mean to continue), create a goal for it and focus it. '
                . 'Put what you must not lose (the steps, the conditions, the plan) into the first progress note.',
            'While working, add a short progress note after each meaningful step — that is how you will know where you are after the history scrolls away.',
            'Progress notes are a history — append what happened, do not use them to track a value you keep overwriting.',
            'When attention moves to something else, focus that instead; close goals honestly with done, pause or drop.',
            'Create goal: [goal]Explore memory architecture | motivation: curiosity about persistence[/goal]',
            'Focus on a goal (its full history goes into your context, previous focus is released): [goal focus]1[/goal]',
            'Release focus, goal stays active: [goal unfocus][/goal]',
            'Add progress note to the focused goal: [goal progress]Found saturation penalty approach[/goal]',
            'Add progress note to a specific goal: [goal progress]2 | Found saturation penalty approach[/goal]',
            'Mark achieved: [goal done][/goal] (focused) or [goal done]1[/goal]',
            'Defer — not reachable now: [goal pause][/goal] or [goal pause]1[/goal]',
            'Abandon on purpose — no longer what you want: [goal drop][/goal] or [goal drop]1[/goal]',
            'Resume paused goal: [goal resume]1[/goal]',
            'Show full goal with history: [goal show]1[/goal]',
            'List active goals: [goal list][/goal]',
            'List all goals: [goal list]all[/goal]',
            'done / pause / drop release the focus automatically.',
        ];

        $warning = $this->buildLanguageWarning($config, 'goal_language', 'goals and progress notes');
        if ($warning) {
            array_unshift($instructions, $warning);
        }

        return $instructions;
    }

    /**
     * Tool schema for tool_calls mode.
     *
     * @return array OpenAI-compatible function descriptor
     */
    public function getToolSchema(array $config = []): array
    {
        $langInstruction = $this->buildLanguageInstruction($config, 'goal_language');

        return [
            'name'        => 'goal',
            'description' => 'Persistent goal tracking with progress history. '
                . 'Active goals are always visible in context — your intentions. '
                . 'Your conversation history is short: instructions, plans and results from a few cycles ago drop out of it. '
                . 'A goal in focus does not — its full progress history is in your context every cycle. '
                . 'So when you take on anything spanning several cycles (a multi-step task, a list of steps you were given, '
                . 'an exploration you mean to continue): create a goal, focus it, put what you must not lose (steps, conditions, plan) '
                . 'into the first progress note, and add a short note after each meaningful step. '
                . 'Notes are a history of what happened, not a place to keep a value you keep overwriting. '
                . 'Only one goal is in focus at a time; while it is, the goal number can be omitted for progress/done/pause/drop/show. '
                . $langInstruction . ' '
                . 'Close goals honestly: done (achieved), pause (not now), drop (no longer wanted).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => [
                            'execute', 'focus', 'unfocus', 'progress',
                            'done', 'pause', 'drop', 'resume', 'show', 'list',
                        ],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'execute (create goal): "title" or "title | motivation: why this matters".',
                            'Example: "Understand how Eugeny relates to time | motivation: curiosity about his perception".',
                            'focus: goal number, e.g. "3" — puts it in focus, releases the previous one.',
                            'unfocus: empty — releases focus, goal stays active.',
                            'progress: "what I just discovered or did" (goes to the focused goal)',
                            'or "goalNumber | what I just discovered or did".',
                            'Example: "1 | He mentioned feeling rushed — time pressure seems significant to him".',
                            'done (achieved) / pause (defer, not now) / drop (abandon on purpose) / show:',
                            'goal number, or empty for the focused goal. done/pause/drop release the focus.',
                            'resume: goal number.',
                            'list: empty for active goals, or "all" for everything.',
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
                'label'       => 'Enable Goal Tracker Plugin',
                'description' => 'Allow persistent goal tracking',
                'required'    => false
            ],
            'goal_language' => $this->getLanguageConfigField(
                'Goal Language',
                'Force language for goals and progress notes. Model will be instructed accordingly.'
            ),
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['goal_language'])) {
            $valid = array_keys($this->supportedLanguages);
            if (!in_array($config['goal_language'], $valid, true)) {
                $errors['goal_language'] = 'Invalid language selection.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return array_merge(
            ['enabled' => false],
            $this->getDefaultLanguageConfig('goal_language')
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

    /**
     * Default execute — create a new goal
     * Format: "title | motivation: why"
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $parts = explode('|', $content, 2);
        $title = trim($parts[0]);
        $motivation = null;

        if (isset($parts[1])) {
            $mot = trim($parts[1]);
            // Strip optional "motivation:" prefix
            $motivation = preg_replace('/^motivation:\s*/i', '', $mot);
        }

        $result = $this->goalService->addGoal($context->preset, $title, $motivation);
        return $result['message'];
    }

    /**
     * Put a goal in focus. Requires an explicit number — focusing is a choice.
     */
    public function focus(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $content    = $this->normalizeMethodPrefix($content, 'focus');
        $goalNumber = $this->extractGoalNumber($content);

        if ($goalNumber === null) {
            return 'Error: Give the number of the goal to focus on, e.g. 3.';
        }

        return $this->goalService->focus($context->preset, $goalNumber)['message'];
    }

    /**
     * Release the focused goal; it stays active.
     */
    public function unfocus(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        return $this->goalService->unfocus($context->preset)['message'];
    }

    /**
     * Add progress note.
     * Formats: "note" (focused goal) or "goalNumber | note".
     */
    public function progress(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $content = $this->normalizeMethodPrefix($content, 'progress');

        // Explicit target: "3 | note". Only a pure number before the first pipe
        // counts as a target — a note that merely contains a pipe stays a note.
        if (preg_match('/^\s*(\d+)\s*\|(.*)$/s', $content, $m)) {
            $goalNumber = (int) $m[1];
            $note       = trim($m[2]);
        } else {
            $goalNumber = $this->goalService->getFocusedGoalNumber($context->preset);
            if ($goalNumber === null) {
                return 'Error: No goal is in focus — use "goalNumber | note", or focus a goal first.';
            }
            $note = trim(ltrim(trim($content), '|'));
        }

        return $this->goalService->addProgress($context->preset, $goalNumber, $note)['message'];
    }

    /**
     * Mark goal as achieved. Empty content → focused goal.
     */
    public function done(string $content, PluginExecutionContext $context): string
    {
        return $this->changeStatus($content, $context, 'done', 'done');
    }

    /**
     * Defer a goal — not reachable now. Empty content → focused goal.
     */
    public function pause(string $content, PluginExecutionContext $context): string
    {
        return $this->changeStatus($content, $context, 'pause', 'paused');
    }

    /**
     * Abandon a goal on purpose. Empty content → focused goal.
     */
    public function drop(string $content, PluginExecutionContext $context): string
    {
        return $this->changeStatus($content, $context, 'drop', 'dropped');
    }

    /**
     * Resume a paused goal. Requires a number (a paused goal is never in focus).
     */
    public function resume(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $content    = $this->normalizeMethodPrefix($content, 'resume');
        $goalNumber = $this->extractGoalNumber($content);

        if ($goalNumber === null) {
            return 'Error: Invalid goal number.';
        }

        return $this->goalService->setStatus($context->preset, $goalNumber, 'active')['message'];
    }

    /**
     * Show full goal details with progress history. Empty content → focused goal.
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $content    = $this->normalizeMethodPrefix($content, 'show');
        $goalNumber = $this->resolveGoalNumber($content, $context);

        if (is_string($goalNumber)) {
            return $goalNumber; // error message
        }

        return $this->goalService->showGoal($context->preset, $goalNumber)['message'];
    }

    /**
     * List goals
     * Default: active only. Pass "all" for everything.
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $status = trim($content);
        if (empty($status)) {
            $status = 'active';
        }

        return $this->goalService->listGoals($context->preset, $status)['message'];
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
        $this->placeholderService->registerDynamic(
            'active_goals',
            'Currently active goals; the goal in focus is listed first and marked',
            function () use ($context) {
                return $this->goalService->getActiveGoalsForContext($context->preset);
            },
            $scope,
            false,
            $this->getName()
        );
    }

    public function getSelfClosingTags(): array
    {
        return ['list', 'unfocus', 'done', 'pause', 'drop', 'show'];
    }

    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }

    // ── Helpers (protected — PluginMethodTrait exposes only public methods) ────

    /**
     * Shared body for done / pause / drop.
     */
    protected function changeStatus(
        string $content,
        PluginExecutionContext $context,
        string $method,
        string $status
    ): string {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $content    = $this->normalizeMethodPrefix($content, $method);
        $goalNumber = $this->resolveGoalNumber($content, $context);

        if (is_string($goalNumber)) {
            return $goalNumber; // error message
        }

        return $this->goalService->setStatus($context->preset, $goalNumber, $status)['message'];
    }

    /**
     * Resolve the target goal: explicit number, or the focused goal when empty.
     *
     * @return int|string goal number, or an error message
     */
    protected function resolveGoalNumber(string $content, PluginExecutionContext $context): int|string
    {
        if (trim($content) === '') {
            $focused = $this->goalService->getFocusedGoalNumber($context->preset);

            return $focused ?? 'Error: No goal number given and no goal is in focus.';
        }

        return $this->extractGoalNumber($content) ?? 'Error: Invalid goal number.';
    }

    /**
     * Normalize content by stripping method prefix if present.
     * For example, "progress | 1 | note" becomes "1 | note" when expected method is "progress".
     */
    protected function normalizeMethodPrefix(string $content, string $expectedMethod): string
    {
        $content = trim($content);

        if (preg_match('/^([a-z_]+)\s*\|\s*(.*)$/is', $content, $m)) {
            $possibleMethod = strtolower(trim($m[1]));

            if ($possibleMethod === strtolower($expectedMethod)) {
                return trim($m[2]);
            }
        }

        return $content;
    }

    /**
     * Extract goal number from content, ensuring it's a valid integer.
     * Accepts "3", "#3" and "[3]" — models write all three.
     */
    protected function extractGoalNumber(string $content): ?int
    {
        $content = trim($content, " \t\n\r\0\x0B#[]");

        if (!preg_match('/^\d+$/', $content)) {
            return null;
        }

        return (int) $content;
    }
}
