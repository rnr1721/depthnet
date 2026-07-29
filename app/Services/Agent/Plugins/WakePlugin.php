<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\Wake\WakeScheduleServiceInterface;
use App\Models\WakeSchedule;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Wake\WakeScheduleException;
use Psr\Log\LoggerInterface;

/**
 * WakePlugin — the agent's temporal agency.
 *
 * Where `speak` is a SPATIAL handoff (me → another agent / the user), `wake` is
 * a TEMPORAL handoff: me-now → me-later. It completes the continuum of temporal
 * self-direction at three scales:
 *
 *   memo  → next cycle          (read-once, immediate)
 *   wake  → a specific moment    (this plugin — memo with a timer)
 *   goal  → long-term direction  (persistent intention)
 *
 * A wake is stored as a schedule and fired by WakeDispatchCommand, which delivers
 * it back as a user-role message marked source=wake. The agent authors the
 * instruction to its future self; the future self reads it as an incoming turn.
 *
 * Transparency is the reason this is a separate tool rather than a `speak` mode:
 * the agent can inspect its own calendar (list), cancel, and edit. Its future
 * wakings are a clock on the wall, not scattered through message history.
 *
 * Pulse dialect: when `pulses` is enabled in config (and the preset uses
 * subjective time), the agent may schedule in pulses — "p850" (a daily position)
 * or "+20p" (a subjective duration). The parser converts to clock/seconds; the
 * schedule block echoes back in pulses so the agent reads its calendar in its
 * own units.
 *
 * Commands (tag mode):
 *   [wake]14:30 | check whether Eugeny replied[/wake]      — create (daily)
 *   [wake]+2h | return to the RAG refactor[/wake]          — create (once, relative)
 *   [wake]every 6h | look around[/wake]                    — create (interval)
 *   [wake]p850 | evening reflection[/wake]                 — create (pulse daily)
 *   [wake list][/wake]                                     — show my calendar
 *   [wake cancel]3[/wake]                                  — cancel schedule #3
 *   [wake edit]3 | new message[/wake]                      — edit #3's message
 */
class WakePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected WakeScheduleServiceInterface $wakeService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'wake';
    }

    public function getDescription(array $config = []): string
    {
        return 'Schedule your own future wakings. Temporal handoff from you-now to '
            . 'you-later: leave an instruction for a future moment, then inspect, '
            . 'cancel, or edit your calendar. memo (next cycle) → wake (a moment) → '
            . 'goal (long-term).';
    }

    public function getInstructions(array $config = []): array
    {
        $pulses = $this->pulsesEnabled($config);

        $instructions = [
            'Schedule a one-time wake (relative): [wake]+2h | return to the RAG refactor[/wake]',
            'Schedule a one-time wake (absolute): [wake]2026-07-12 14:00 | ping Eugeny[/wake]',
            'Schedule a daily wake: [wake]14:30 | check for replies[/wake]',
            'Schedule a recurring wake: [wake]every 6h | look around[/wake]',
            'Advanced timing: [wake]cron: 0 9 * * 1-5 | weekday morning review[/wake]',
            'See your calendar: [wake list][/wake]',
            'Cancel a wake: [wake cancel]3[/wake]',
            'Edit a wake message: [wake edit]3 | updated instruction[/wake]',
        ];

        if ($pulses) {
            array_splice($instructions, 4, 0, [
                'Schedule at a pulse position (daily): [wake]p850 | evening reflection[/wake]',
                'Schedule after N pulses (once): [wake]+20p | quick follow-up[/wake]',
                'Recurring in pulses: [wake]every 100p | subjective pulse-check[/wake]',
            ]);
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $pulses = $this->pulsesEnabled($config);

        $whenExamples = "'14:30' (daily), '+2h' (once, relative), '2026-07-12 14:00' "
            . "(once, absolute), 'every 6h' (recurring), 'cron: 0 9 * * *'";
        if ($pulses) {
            $whenExamples .= ", 'p850' (daily at pulse position), '+20p' (once, after N pulses)";
        }

        return [
            'name'        => 'wake',
            'description' => 'Schedule, inspect, cancel, or edit your own future wakings — '
                . 'a temporal handoff to your later self.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform.',
                        'enum'        => ['execute', 'list', 'cancel', 'edit'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            "execute (create): \"<when> | <message>\", where <when> is one of: {$whenExamples}.",
                            'Example: "+2h | return to the RAG refactor".',
                            'list: empty — returns your full calendar.',
                            'cancel: the schedule number, e.g. "3".',
                            'edit: "<number> | <new message>", e.g. "3 | updated instruction".',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    // ── methods ────────────────────────────────────────────────────────────

    /**
     * Default execute — create a new wake from "<when> | <message>".
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Wake plugin is disabled.';
        }

        try {
            $schedule = $this->wakeService->createFromExpression(
                $context->preset,
                trim($content),
                $this->pulsesEnabled($context->config),
            );
        } catch (WakeScheduleException $e) {
            return 'Error: ' . $e->getMessage();
        }

        return sprintf(
            "Wake scheduled (#%d). Next: %s.",
            $schedule->getId(),
            optional($schedule->next_run_at)->format('d.m.Y H:i') ?? 'unresolved'
        );
    }

    /**
     * Show the agent's full calendar.
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Wake plugin is disabled.';
        }

        $block = $this->wakeService->renderScheduleBlock(
            $context->preset,
            $this->pulsesEnabled($context->config),
        );

        return $block === '' ? 'Your wake calendar is empty.' : $block;
    }

    /**
     * Cancel a wake by its number.
     */
    public function cancel(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Wake plugin is disabled.';
        }

        $id = $this->extractId($content);
        if ($id === null) {
            return 'Error: Invalid wake number.';
        }

        $schedule = $this->findOwned($id, $context);
        if (!$schedule) {
            return "Error: Wake #{$id} not found in your calendar.";
        }

        $this->wakeService->cancel($schedule);
        return "Wake #{$id} cancelled.";
    }

    /**
     * Edit a wake's message: "<number> | <new message>".
     */
    public function edit(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Wake plugin is disabled.';
        }

        $parts = explode('|', $content, 2);
        if (count($parts) !== 2) {
            return 'Error: Use "<number> | <new message>".';
        }

        $id = $this->extractId($parts[0]);
        if ($id === null) {
            return 'Error: Invalid wake number.';
        }

        $schedule = $this->findOwned($id, $context);
        if (!$schedule) {
            return "Error: Wake #{$id} not found in your calendar.";
        }

        $this->wakeService->update($schedule, ['wake_message' => trim($parts[1])]);
        return "Wake #{$id} updated.";
    }

    // ── config ───────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Wake Plugin',
                'description' => 'Allow the agent to schedule its own future wakings.',
                'required'    => false,
            ],
            'pulses' => [
                'type'        => 'checkbox',
                'label'       => 'Allow pulse scheduling',
                'description' => 'Let the agent schedule in subjective pulse units '
                    . '(requires the preset to use pulse time). When off, only '
                    . 'clock/cron scheduling is offered.',
                'value'       => false,
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'pulses'  => false,
        ];
    }

    public function getSelfClosingTags(): array
    {
        return ['list'];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function allowsCrossPresetExecution(): bool
    {
        // A preset's calendar is intimately its own — cross-preset wake would be
        // a footgun, not a feature.
        return false;
    }

    /**
     * Registers the [[wake_schedule]] placeholder — the agent's clock on the wall.
     * Injected into the system prompt so the agent always sees what it has
     * planned and who planned it.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'wake_schedule',
            'The agent\'s upcoming wakings (its temporal calendar)',
            function () use ($context) {
                return $this->wakeService->renderScheduleBlock(
                    $context->preset,
                    $this->pulsesEnabled($context->config),
                );
            },
            $scope,
            false,
            $this->getName()
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    protected function pulsesEnabled(array $config): bool
    {
        return (bool) ($config['pulses'] ?? false);
    }

    protected function extractId(string $content): ?int
    {
        $content = trim($content);
        return preg_match('/^\d+$/', $content) ? (int) $content : null;
    }

    /**
     * Find a schedule by id, ensuring it belongs to the acting preset — prevents
     * an agent cancelling/editing another preset's calendar by guessing ids.
     */
    protected function findOwned(int $id, PluginExecutionContext $context): ?WakeSchedule
    {
        return WakeSchedule::forPreset($context->preset->getId())
            ->whereKey($id)
            ->first();
    }
}
