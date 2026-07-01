<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\Contract\ContractRuntimeServiceInterface;
use App\Contracts\Agent\Contract\ContractServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Contract\ContractDefinition;
use App\Services\Agent\Contract\ContractStatus;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * ContractPlugin — the agent's interface to its own metabolism.
 *
 * Thin command layer over ContractService (CRUD + lifecycle) and the runtime
 * (derived flags for the placeholder). It describes the *dashboard and the
 * levers* — what a flag means, what commands exist — and never tells the agent
 * what to do about a flag. That discipline ("mechanism, not behaviour") holds in
 * both getInstructions (tag mode) and getToolSchema (tool_calls mode).
 *
 * Storage, evaluation, and ticking live elsewhere; this plugin only lets the
 * agent author and inspect contracts and read raised flags.
 *
 * Commands:
 *   [contract define]{...json...}[/contract]  — create/update (defaults to hypothesis)
 *   [contract list][/contract]                — list contracts with status
 *   [contract show]name[/contract]            — full definition + history
 *   [contract promote]name[/contract]         — hypothesis → active
 *   [contract suspend]name[/contract]         — active → suspended
 *   [contract resume]name[/contract]          — suspended → active
 *   [contract revoke]name[/contract]          — → hypothesis
 *
 * Placeholder [[active_contracts]] shows active contracts and currently raised
 * flags — the result of the metabolism; `show` reveals the cause.
 */
class ContractPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'contract';

    public function __construct(
        protected ContractServiceInterface               $contractService,
        protected ContractRuntimeServiceInterface        $runtime,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected LoggerInterface                        $logger,
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Contracts — your metabolism. Cheap deterministic rules that watch your own '
            . 'traces (journal, state) and raise flags when a threshold is crossed, without a '
            . 'thinking cycle. You author them; they free you from counting and remembering. '
            . 'Active contracts and raised flags are visible via system message.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'A contract is one of four forms: '
                . 'THR_T (≥ N seconds since a matching trace), '
                . 'THR_C (≥ N matching traces in a window), '
                . 'ACC (a value accumulates over time, capped), '
                . 'DEC (a value decays each tick, floored).',
            'Define (JSON body): [contract define]{"name":"idle_flag","form":"THR_T",'
                . '"trigger":{"match":{"source":"journal","type":"action"},"threshold_seconds":600},'
                . '"action":{"type":"set_flag","flag":"idle"}}[/contract]',
            'New contracts start as hypothesis (observed, not executing). Promote to run them.',
            'List your contracts: [contract list][/contract]',
            'Inspect one (full definition + history): [contract show]idle_flag[/contract]',
            'Promote to active: [contract promote]idle_flag[/contract]',
            'Suspend (pause, keep definition): [contract suspend]idle_flag[/contract]',
            'Resume a suspended contract: [contract resume]idle_flag[/contract]',
            'Revoke to hypothesis (stop executing, keep for review): [contract revoke]idle_flag[/contract]',
            'Actions a contract may raise, each with its required fields: '
                . 'set_flag {flag} (a named signal), create_goal {flag} '
                . '(a goal-candidate flag you then turn into a goal), '
                . 'nudge_state {target, delta} (shift a state value), '
                . 'inject_memo {text} (the line written to your next memo — without text, nothing is written).',
            'Flags shown in system message mean a condition is currently met. '
                . 'What you do about a flag is yours to decide — a flag is a signal, not an instruction.',
            'A contract marked "vital" can be revoked to hypothesis but not edited or deleted '
                . 'while active — revoke it first, then change it. This is a deliberate safeguard, not a lock.',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'contract',
            'description' => 'Your metabolism: deterministic rules over your own traces that raise '
                . 'flags without a thinking cycle. Four forms — THR_T (time since a matching trace), '
                . 'THR_C (count of matching traces in a window), ACC (value accumulates, capped), '
                . 'DEC (value decays per tick, floored). Actions: set_flag, create_goal, nudge_state, '
                . 'inject_memo. A flag means a condition is met; deciding what to do about it is yours. '
                . 'Active contracts and raised flags are visible via system message.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['define', 'list', 'show', 'promote', 'suspend', 'resume', 'revoke'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'define: a JSON object {name, form, trigger, action, [vital], [source], '
                                . '[suspend_when], [status]}. New contracts default to status "hypothesis".',
                            'forms: THR_T {trigger:{match,threshold_seconds}}, '
                                . 'THR_C {trigger:{match,threshold_count,[window_seconds]}}, '
                                . 'ACC {trigger:{target,weight,cap}}, DEC {trigger:{target,rate,floor}}.',
                            'actions: set_flag {action:{type,flag}}, create_goal {action:{type,flag}}, '
                                . 'nudge_state {action:{type,target,delta}}, '
                                . 'inject_memo {action:{type,text}} — text is the line written to your memo, required.',
                            'match: {source:"journal", [type], [outcome], [contains], [match_mode]}.',
                            'show/promote/suspend/resume/revoke: the contract name.',
                            'list: leave empty.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Contract error: check your syntax.';
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function execute(string $content, PluginExecutionContext $context): string
    {
        // Safe read-only default.
        return $this->list($content, $context);
    }

    public function getAvailableMethods(): array
    {
        return ['define', 'list', 'show', 'promote', 'suspend', 'resume', 'revoke'];
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * [contract define]{...json...}[/contract]
     */
    public function define(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Contract plugin is disabled.';
        }

        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded)) {
            return 'Error: define expects a JSON object. '
                . 'Example: {"name":"x","form":"THR_T","trigger":{...},"action":{...}}';
        }

        try {
            $definition = ContractDefinition::fromArray($decoded);
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }

        $result = $this->contractService->save($context->preset, $definition);

        if (!$result['success']) {
            $errors = !empty($result['errors']) ? ' (' . implode('; ', $result['errors']) . ')' : '';
            return $result['message'] . $errors;
        }

        return $result['message'];
    }

    /**
     * [contract list][/contract]
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Contract plugin is disabled.';
        }

        $contracts = $this->contractService->all($context->preset);

        if (empty($contracts)) {
            return 'No contracts defined.';
        }

        $lines = ['Contracts:'];
        foreach ($contracts as $c) {
            $vital = $c->vital ? ' [vital]' : '';
            $lines[] = "  {$c->name} — {$c->form->value}, {$c->status->value}{$vital}";
        }

        return implode("\n", $lines);
    }

    /**
     * [contract show]name[/contract]
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Contract plugin is disabled.';
        }

        $name = trim($content);
        if ($name === '') {
            return 'Error: Provide a contract name.';
        }

        $c = $this->contractService->find($context->preset, $name);
        if ($c === null) {
            return "Contract '{$name}' not found.";
        }

        return $this->renderFull($c);
    }

    /**
     * [contract promote]name[/contract] — hypothesis → active.
     */
    public function promote(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, ContractStatus::ACTIVE, 'promote');
    }

    /**
     * [contract suspend]name[/contract] — active → suspended.
     */
    public function suspend(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, ContractStatus::SUSPENDED, 'suspend');
    }

    /**
     * [contract resume]name[/contract] — suspended → active.
     */
    public function resume(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, ContractStatus::ACTIVE, 'resume');
    }

    /**
     * [contract revoke]name[/contract] — → hypothesis.
     */
    public function revoke(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, ContractStatus::HYPOTHESIS, 'revoke');
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic(
            'active_contracts',
            'Active contracts and currently raised flags',
            fn () => $this->renderActiveContracts($context->preset),
            $scope
        );
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Contract Plugin',
                'description' => 'Metabolism engine — deterministic rules over the agent\'s own traces',
                'required'    => false,
            ],
            'max_active_contracts' => [
                'type'        => 'number',
                'label'       => 'Max Active Contracts',
                'description' => 'Cap on simultaneously active contracts per preset',
                'min'         => 1,
                'max'         => 500,
                'value'       => 50,
                'required'    => false,
            ],
            'tick_min_seconds' => [
                'type'        => 'number',
                'label'       => 'Minimum Tick Interval (seconds)',
                'description' => 'Coalesce ticks: skip if fewer than this many seconds since the last tick',
                'min'         => 0,
                'max'         => 86400,
                'value'       => 60,
                'required'    => false,
            ],
            'default_suspend_flag' => [
                'type'        => 'text',
                'label'       => 'Global Suspend Flag',
                'description' => 'Optional flag name that, while raised, suspends every contract for that tick',
                'value'       => '',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['max_active_contracts'])) {
            $v = (int) $config['max_active_contracts'];
            if ($v < 1 || $v > 500) {
                $errors['max_active_contracts'] = 'Must be between 1 and 500';
            }
        }

        if (isset($config['tick_min_seconds'])) {
            $v = (int) $config['tick_min_seconds'];
            if ($v < 0 || $v > 86400) {
                $errors['tick_min_seconds'] = 'Must be between 0 and 86400';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'              => false,
            'max_active_contracts' => 50,
            'tick_min_seconds'     => 60,
            'default_suspend_flag' => '',
        ];
    }

    // ── Boilerplate ───────────────────────────────────────────────────────────

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

    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }

    // ── Private rendering ─────────────────────────────────────────────────────

    private function doTransition(
        string $content,
        PluginExecutionContext $context,
        ContractStatus $to,
        string $reason,
    ): string {
        if (!$context->enabled) {
            return 'Error: Contract plugin is disabled.';
        }

        $name = trim($content);
        if ($name === '') {
            return 'Error: Provide a contract name.';
        }

        // Cap only matters when entering active; 0 elsewhere (no-op).
        $maxActive = $to === ContractStatus::ACTIVE
            ? (int) $context->get('max_active_contracts', 0)
            : 0;

        return $this->contractService
            ->transition($context->preset, $name, $to, $reason, $maxActive)['message'];
    }

    private function renderActiveContracts(\App\Models\AiPreset $preset): string
    {
        $active = $this->contractService->ofStatus($preset, ContractStatus::ACTIVE);
        $flags  = $this->runtime->raisedFlags($preset);

        if (empty($active) && empty($flags)) {
            return 'No active contracts.';
        }

        $parts = [];

        if (!empty($flags)) {
            $flagStrs = array_map(function (array $f) {
                $kind = $f['kind'] === 'goal_candidate' ? ' → goal_candidate' : '';
                return $f['flag'] . $kind . " (by {$f['by']})";
            }, $flags);
            $parts[] = 'Flags: ' . implode(' | ', $flagStrs);
        }

        if (!empty($active)) {
            $names = array_map(fn (ContractDefinition $c) => "{$c->name}({$c->form->value})", $active);
            $parts[] = 'Active: ' . implode(', ', $names);
        }

        return implode("\n", $parts);
    }

    private function renderFull(ContractDefinition $c): string
    {
        $lines = [
            "Contract: {$c->name}",
            "Form:     {$c->form->value}",
            "Status:   {$c->status->value}" . ($c->vital ? ' [vital]' : ''),
            "Source:   {$c->source}",
        ];

        if ($c->suspendWhen !== null) {
            $lines[] = "Suspend when: {$c->suspendWhen}";
        }

        $lines[] = 'Trigger:  ' . json_encode($c->trigger, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines[] = 'Action:   ' . json_encode($c->action, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!empty($c->history)) {
            $lines[] = 'History:';
            foreach ($c->history as $h) {
                $from   = $h['from'] ?? '?';
                $to     = $h['to'] ?? '?';
                $at     = $h['at'] ?? '';
                $reason = isset($h['reason']) && $h['reason'] !== null ? " ({$h['reason']})" : '';
                $lines[] = "  {$from} → {$to} @ {$at}{$reason}";
            }
        }

        return implode("\n", $lines);
    }
}
