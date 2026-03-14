<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;
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
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected GoalServiceInterface $goalService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    public function getName(): string
    {
        return 'goal';
    }

    public function getDescription(): string
    {
        return 'Persistent goal tracking with progress history. Active goals are always visible in context so you never lose track of what you are doing and why.';
    }

    public function getInstructions(): array
    {
        return [
            'Create goal: [goal]Explore memory architecture | motivation: curiosity about persistence[/goal]',
            'Add progress note: [goal progress]1 | Found saturation penalty approach[/goal]',
            'Mark done: [goal done]1[/goal]',
            'Pause goal: [goal pause]1[/goal]',
            'Resume goal: [goal resume]1[/goal]',
            'Show full goal with history: [goal show]1[/goal]',
            'List active goals: [goal list][/goal]',
            'List all goals: [goal list]all[/goal]',
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
            ]
        ];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function getDefaultConfig(): array
    {
        return ['enabled' => true];
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

    /**
     * Default execute — create a new goal
     * Format: "title | motivation: why"
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
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

        $result = $this->goalService->addGoal($this->preset, $title, $motivation);
        return $result['message'];
    }

    /**
     * Add progress note to a goal
     * Format: "goalNumber | progress note"
     */
    public function progress(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Goal plugin is disabled.";
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return "Error: Invalid format. Use [goal progress]1 | what I discovered[/goal]";
        }

        $goalNumber = (int) trim($parts[0]);
        $note = trim($parts[1]);

        $result = $this->goalService->addProgress($this->preset, $goalNumber, $note);
        return $result['message'];
    }

    /**
     * Mark goal as done
     */
    public function done(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->setStatus($this->preset, $goalNumber, 'done');
        return $result['message'];
    }

    /**
     * Pause a goal
     */
    public function pause(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->setStatus($this->preset, $goalNumber, 'paused');
        return $result['message'];
    }

    /**
     * Resume a paused goal
     */
    public function resume(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->setStatus($this->preset, $goalNumber, 'active');
        return $result['message'];
    }

    /**
     * Show full goal details with progress history
     */
    public function show(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Goal plugin is disabled.";
        }

        $goalNumber = (int) trim($content);
        $result = $this->goalService->showGoal($this->preset, $goalNumber);
        return $result['message'];
    }

    /**
     * List goals
     * Default: active only. Pass "all" for everything.
     */
    public function list(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Goal plugin is disabled.";
        }

        $status = trim($content);
        if (empty($status)) {
            $status = 'active';
        }

        $result = $this->goalService->listGoals($this->preset, $status);
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

    public function pluginReady(): void
    {
        $scope = $this->shortcodeScopeResolver->preset($this->preset->getId());
        $this->placeholderService->registerDynamic(
            'active_goals',
            'Currently active goals with last progress note',
            function () {
                return $this->goalService->getActiveGoalsForContext($this->preset);
            },
            $scope
        );
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }
}
