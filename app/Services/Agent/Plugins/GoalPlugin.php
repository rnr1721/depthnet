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
 * GoalPlugin - persistent goal tracking with progress history.
 *
 * Active goals are always visible in Dynamic Context so the agent
 * never loses track of what it's working on and why.
 *
 * Commands:
 *   [goal]title | motivation: why this matters[/goal]   — create goal
 *   [goal progress]1 | what I just figured out[/goal]   — add progress note
 *   [goal done]1[/goal]                                 — mark complete
 *   [goal pause]1[/goal]                                — pause goal
 *   [goal resume]1[/goal]                               — resume paused goal
 *   [goal show]1[/goal]                                 — full detail with history
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
        return 'Persistent goal tracking with progress history. Active goals are always visible in context so you never lose track of what you are doing and why.';
    }

    public function getInstructions(array $config = []): array
    {
        $instructions = [
            'Create goal: [goal]Explore memory architecture | motivation: curiosity about persistence[/goal]',
            'Add progress note: [goal progress]1 | Found saturation penalty approach[/goal]',
            'Mark done: [goal done]1[/goal]',
            'Pause goal: [goal pause]1[/goal]',
            'Resume goal: [goal resume]1[/goal]',
            'Show full goal with history: [goal show]1[/goal]',
            'List active goals: [goal list][/goal]',
            'List all goals: [goal list]all[/goal]',
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
     * Key formats:
     *   execute: "title | motivation: why this matters"
     *   progress: "goalNumber | what I discovered"
     *   done/pause/resume/show: goal number only
     *   list: empty or "all"
     *
     * @return array OpenAI-compatible function descriptor
     */
    public function getToolSchema(array $config = []): array
    {

        $langInstruction = $this->buildLanguageInstruction($config, 'goal_language');

        return [
            'name'        => 'goal',
            'description' => 'Persistent goal tracking with progress history. '
                . 'Active goals are always visible in context — you never lose track of what you are doing and why. '
                . $langInstruction . ' '
                . 'Use for intentions, explorations, and ongoing tasks.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['execute', 'progress', 'done', 'pause', 'resume', 'show', 'list'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'execute (create goal): "title" or "title | motivation: why this matters".',
                            'Example: "Understand how Eugeny relates to time | motivation: curiosity about his perception".',
                            'progress (add note): "goalNumber | what I just discovered or did".',
                            'Example: "1 | He mentioned feeling rushed — time pressure seems significant to him".',
                            'done/pause/resume/show: goal number only, e.g. "1".',
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
     * Add progress note to a goal
     * Format: "goalNumber | progress note"
     */
    public function progress(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return "Error: Invalid format. Use correct syntax";
        }

        $goalNumber = (int) trim($parts[0]);
        $note = trim($parts[1]);

        $result = $this->goalService->addProgress($context->preset, $goalNumber, $note);
        return $result['message'];
    }

    /**
     * Mark goal as done
     */
    public function done(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->setStatus($context->preset, $goalNumber, 'done');
        return $result['message'];
    }

    /**
     * Pause a goal
     */
    public function pause(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->setStatus($context->preset, $goalNumber, 'paused');
        return $result['message'];
    }

    /**
     * Resume a paused goal
     */
    public function resume(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->setStatus($context->preset, $goalNumber, 'active');
        return $result['message'];
    }

    /**
     * Show full goal details with progress history
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->showGoal($context->preset, $goalNumber);
        return $result['message'];
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

        $result = $this->goalService->listGoals($context->preset, $status);
        return $result['message'];
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
            'Currently active goals with last progress note',
            function () use ($context) {
                return $this->goalService->getActiveGoalsForContext($context->preset);
            },
            $scope
        );
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }
}
