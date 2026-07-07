<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\Behavior\BehaviorPatternServiceInterface;
use App\Contracts\Agent\Behavior\BehaviorRuntimeServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\BehaviorPattern;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * BehaviorPlugin — the agent's interface to its own adaptive behavior system.
 *
 * Thin command layer over BehaviorPatternService (CRUD + lifecycle) and the
 * runtime (fitness/activation state for the placeholder). Like ContractPlugin, it
 * describes the *dashboard and the levers* — what a pattern is, what commands
 * exist — and never tells the agent which pattern to prefer. Selection is the
 * engine's job; authoring is the agent's.
 *
 * ABS is a bolt-on: the coordinator only acts when the 'behavior.enabled'
 * metadata flag is set. This plugin is the switch — registerShortcodes() syncs
 * that flag from the plugin's enabled config, so enabling the plugin enables ABS.
 *
 * Commands:
 *   [behavior define]{...json...}[/behavior]  — create/update (defaults to hypothesis)
 *   [behavior list][/behavior]                — patterns with status + fitness
 *   [behavior show]name[/behavior]            — full definition + selection stats
 *   [behavior promote]name[/behavior]         — hypothesis → active
 *   [behavior retire]name[/behavior]          — active → retired
 *   [behavior revoke]name[/behavior]          — → hypothesis
 *
 * Placeholder [[behavior_patterns]] shows the active population with fitness —
 * the state of selection; `show` reveals one pattern in full.
 */
class BehaviorPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'behavior';

    /** Metadata the coordinator reads. Keep in sync with BehaviorCoordinator. */
    private const META_PLUGIN  = 'behavior';
    private const META_ENABLED = 'enabled';

    public function __construct(
        protected BehaviorPatternServiceInterface        $patterns,
        protected BehaviorRuntimeServiceInterface        $runtime,
        protected PluginMetadataServiceInterface         $meta,
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
        return 'Behavior — your adaptive behavior system. A population of competing patterns '
            . '("when X, lean toward Y") under selection pressure: each cycle one pattern leads '
            . 'and all that were ready learn from the outcome. Patterns are hypotheses distilled '
            . 'from recurring tendencies you notice in your own behavior. '
            . 'The active population and fitness are injected in system message.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            $this->getBehaviorPhilosophy($config),
            'A pattern is a light strategy: a trigger (when it applies), an intent (what it leans '
                . 'toward), and an optional behavior hint (how it shapes the response). It is NOT a '
                . 'preset and NOT a contract — it competes, it does not fire deterministically.',
            'Define (JSON body): [behavior define]{"name":"deepen","trigger":{"kind":"mood",'
                . '"target":"focus","op":">","value":0.5},"intent":"Stay with the current thread; '
                . 'go deeper, not wider.","priority":1.0}[/behavior]',
            'Trigger kinds (structural, code-evaluated): '
                . 'mood {target, op, value} — an emotional dimension crosses a threshold; '
                . 'pulse {from, to} — current pulse falls in a range (requires pulse dates).',
            'A pattern MAY carry an optional "lever": when it leads a cycle, it nudges ONE mood '
                . 'dimension by a small signed amount. Shape: '
                . '"lever":{"dimension":"<mood-state>","delta":<-0.5..0.5>}. A pattern with no lever '
                . 'still leans via the placeholder but moves nothing and earns no discriminating '
                . 'fitness — it only competes on how often it leads.',
            'Lever fitness is paid ONLY for movement BEYOND your own push: '
                . 'credit = (the dimension\'s change over the cycle) − (the push your lever applied), '
                . 'and only when that surplus is positive AND the cycle was productive. Your own push '
                . 'is subtracted from your own reward, so a pattern cannot confirm itself by pressing '
                . 'its own button — only the surplus the moment adds counts.',
            'Keep delta small. A large push fills the dimension toward its ceiling and leaves the '
                . 'moment no room to show surplus, so the pattern can never confirm. An unconfirmed '
                . 'lever is never penalised — it simply earns nothing and decays like any idle '
                . 'pattern. A pattern is a hypothesis about yourself, not a promise; a guess that did '
                . 'not bear out is an observation, not a fault.',
            'A lever may target the same dimension as your trigger (that state accumulates over '
                . 'cycles — cannot self-confirm, but ratchets up) or a different one (the credited '
                . 'surplus stays unambiguous). Either is valid; the choice is yours.',
            'New patterns start as hypothesis (in the population but observed, not yet competing). '
                . 'Promote to let them compete.',
            'List your patterns (with fitness): [behavior list][/behavior]',
            'Inspect one (definition + selection stats): [behavior show]deepen[/behavior]',
            'Promote to active (let it compete): [behavior promote]deepen[/behavior]',
            'Retire (stop competing, keep for review): [behavior retire]deepen[/behavior]',
            'Revoke to hypothesis: [behavior revoke]deepen[/behavior]',
            'A pattern may be "immune" with a forced_activation_interval: it cannot be edited or '
                . 'deleted while active (revoke to hypothesis first), and the quota guarantees it leads '
                . 'a cycle every N steps regardless of fitness — a reservation for behavior whose worth '
                . 'is not measured by outcomes (presence, care). An immune pattern lives its quota cycle '
                . 'but does not learn from it: its fitness stays unknown by design. An immune pattern '
                . 'does not enact a lever — presence does not learn from outcomes.',
            'Fitness in system message reflects what selection has learned. It is the system\'s '
                . 'signal, not an instruction — you author patterns; which one leads each cycle is the '
                . 'engine\'s to decide.',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'behavior',
            'description' => $this->getBehaviorPhilosophy($config). ' '
                . 'Your adaptive behavior system: a population of competing patterns under '
                . 'selection pressure. Each pattern has a structural trigger (mood/pulse), an intent, and '
                . 'an optional behavior hint. A pattern may also carry a lever that nudges one mood '
                . 'dimension when it leads, earning fitness only for the dimension\'s movement beyond its '
                . 'own push. Each cycle one pattern leads and all that triggered learn from the outcome; '
                . 'fitness accumulates by credit assignment. Immune patterns with a forced-activation '
                . 'quota are protected reservations that do not learn. The active population and fitness '
                . 'are injected in system message.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['define', 'list', 'show', 'promote', 'retire', 'revoke'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'define: a JSON object {name, trigger, [intent], [behavior], [lever], '
                                . '[priority], [immune], [forced_activation_interval], [status]}. New '
                                . 'patterns default to status "hypothesis".',
                            'trigger kinds: mood {kind:"mood", target, op (>,>=,<,<=,==,!=), value}, '
                                . 'pulse {kind:"pulse", from, to}.',
                            'behavior (optional): {hint:"..."} — a soft influence on the response.',
                            'lever (optional): {dimension:"<mood state>", delta:<number -0.5..0.5>} — '
                                . 'when this pattern leads, it nudges that mood dimension by delta. '
                                . 'Fitness is credited ONLY for the dimension\'s change BEYOND this push, '
                                . 'and only on a productive cycle; an unconfirmed lever is never '
                                . 'penalised. Keep delta small so the moment has room to add surplus. '
                                . 'Omit for a lean-only pattern.',
                            'show/promote/retire/revoke: the pattern name.',
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
        return 'Behavior error: check your syntax.';
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function execute(string $content, PluginExecutionContext $context): string
    {
        // Safe read-only default.
        return $this->list($content, $context);
    }

    public function getAvailableMethods(): array
    {
        return ['define', 'list', 'show', 'promote', 'retire', 'revoke'];
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * [behavior define]{...json...}[/behavior]
     */
    public function define(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Behavior plugin is disabled.';
        }

        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded)) {
            return 'Error: define expects a JSON object. '
                . 'Example: {"name":"x","trigger":{"kind":"mood","target":"focus","op":">","value":0.5}}';
        }

        $result = $this->patterns->save($context->preset, $decoded);

        if (!$result['success']) {
            $errors = !empty($result['errors']) ? ' (' . implode('; ', $result['errors']) . ')' : '';
            return $result['message'] . $errors;
        }

        return $result['message'];
    }

    /**
     * [behavior list][/behavior]
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Behavior plugin is disabled.';
        }

        $patterns = $this->patterns->all($context->preset);

        if (empty($patterns)) {
            return 'No patterns defined.';
        }

        $lines = ['Patterns:'];
        foreach ($patterns as $p) {
            $immune  = $p->immune ? ' [immune]' : '';
            $fitness = number_format((float) $p->fitness, 2);
            $lines[] = "  {$p->name} — {$p->status}{$immune}, fitness {$fitness}, "
                . "priority {$p->priority}, activations {$p->activation_count}";
        }

        return implode("\n", $lines);
    }

    /**
     * [behavior show]name[/behavior]
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Behavior plugin is disabled.';
        }

        $name = trim($content);
        if ($name === '') {
            return 'Error: Provide a pattern name.';
        }

        $p = $this->patterns->find($context->preset, $name);
        if ($p === null) {
            return "Pattern '{$name}' not found.";
        }

        return $this->renderFull($p);
    }

    /**
     * [behavior promote]name[/behavior] — hypothesis → active.
     */
    public function promote(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, 'active', 'promote');
    }

    /**
     * [behavior retire]name[/behavior] — active → retired.
     */
    public function retire(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, 'retired', 'retire');
    }

    /**
     * [behavior revoke]name[/behavior] — → hypothesis.
     */
    public function revoke(string $content, PluginExecutionContext $context): string
    {
        return $this->doTransition($content, $context, 'hypothesis', 'revoke');
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        // Sync the ABS activation flag from the plugin's enabled config so that
        // enabling the plugin == enabling ABS (the coordinator reads this flag).
        // Done here because registerShortcodes runs when the preset is applied,
        // every cycle, cheaply — keeping the switch and the plugin in lockstep.
        $this->syncEnabledFlag($context);

        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic(
            'behavior_patterns',
            'Active behavior patterns with fitness (the state of selection)',
            fn () => $this->renderActivePatterns($context),
            $scope,
            false,
            $this->getName()
        );
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Behavior Plugin (ABS)',
                'description' => 'Adaptive behavior system — a population of competing patterns under selection',
                'required'    => false,
            ],
            'behavior_philosophy' => [
                'type'        => 'textarea',
                'label'       => 'Behavior Philosophy',
                'description' => 'Optional guidance describing how this agent discovers and authors behavior patterns.',
                'value'       => $this->getBehaviorPhilosophy(),
                'required'    => false,
            ],
            'exploration_epsilon' => [
                'type'        => 'number',
                'label'       => 'Exploration Rate (ε)',
                'description' => 'Probability a non-top pattern leads a cycle (0 = always the top score). '
                    . 'A small value lets the system discover whether a never-winning pattern is actually good.',
                'min'         => 0,
                'max'         => 1,
                'step'        => 0.05,
                'value'       => 0.0,
                'required'    => false,
            ],
            'eligible_factor' => [
                'type'        => 'number',
                'label'       => 'Eligible Credit Factor',
                'description' => 'Share of credit a pattern that was ready but did not lead receives '
                    . '(1.0 = same as the leader, 0.5 = half). Presence is credited; leading is worth more.',
                'min'         => 0,
                'max'         => 1,
                'step'        => 0.05,
                'value'       => 0.5,
                'required'    => false,
            ],
            'gamma' => [
                'type'        => 'number',
                'label'       => 'Credit Decay (γ)',
                'description' => 'Per-cycle decay of credit by distance to the outcome (eligibility trace).',
                'min'         => 0,
                'max'         => 1,
                'step'        => 0.05,
                'value'       => 0.8,
                'required'    => false,
            ],
            'horizon' => [
                'type'        => 'number',
                'label'       => 'Credit Horizon (cycles)',
                'description' => 'How many cycles back an outcome reaches when assigning credit.',
                'min'         => 1,
                'max'         => 100,
                'value'       => 10,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        foreach (['exploration_epsilon', 'eligible_factor', 'gamma'] as $key) {
            if (isset($config[$key])) {
                $v = (float) $config[$key];
                if ($v < 0.0 || $v > 1.0) {
                    $errors[$key] = 'Must be between 0 and 1';
                }
            }
        }

        if (isset($config['horizon'])) {
            $v = (int) $config['horizon'];
            if ($v < 1 || $v > 100) {
                $errors['horizon'] = 'Must be between 1 and 100';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'             => false,
            'behavior_philosophy' => $this->getBehaviorPhilosophy(),
            'exploration_epsilon' => 0.0,
            'eligible_factor'     => 0.5,
            'gamma'               => 0.8,
            'horizon'             => 10,
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

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Mirror the plugin's enabled config into the metadata flag the coordinator
     * reads, plus the live-tunable selection knobs. One source of truth (the
     * plugin config), projected to where the engine looks.
     */
    private function syncEnabledFlag(PluginExecutionContext $context): void
    {
        $preset  = $context->preset;
        $enabled = (bool) $context->enabled;

        $current = (bool) $this->meta->get($preset, self::META_PLUGIN, self::META_ENABLED, false);
        if ($current !== $enabled) {
            $this->meta->set($preset, self::META_PLUGIN, self::META_ENABLED, $enabled);
        }

        // Project the tuning knobs so closeCycle()/selector read live config.
        $this->meta->set($preset, self::META_PLUGIN, 'exploration_epsilon', (float) $context->get('exploration_epsilon', 0.0));
        $this->meta->set($preset, self::META_PLUGIN, 'eligible_factor', (float) $context->get('eligible_factor', 0.5));
        $this->meta->set($preset, self::META_PLUGIN, 'gamma', (float) $context->get('gamma', 0.8));
        $this->meta->set($preset, self::META_PLUGIN, 'horizon', (int) $context->get('horizon', 10));
    }

    private function doTransition(
        string $content,
        PluginExecutionContext $context,
        string $to,
        string $reason,
    ): string {
        if (!$context->enabled) {
            return 'Error: Behavior plugin is disabled.';
        }

        $name = trim($content);
        if ($name === '') {
            return 'Error: Provide a pattern name.';
        }

        return $this->patterns->transition($context->preset, $name, $to, $reason)['message'];
    }

    private function renderActivePatterns(PluginExecutionContext $context): string
    {
        $active = $this->patterns->ofStatus($context->preset, 'active');

        if (empty($active)) {
            return 'No active patterns.';
        }

        // Sort by fitness desc so the strongest are visible first.
        usort($active, fn (BehaviorPattern $a, BehaviorPattern $b) => $b->fitness <=> $a->fitness);

        $parts = [];
        foreach ($active as $p) {
            $immune  = $p->immune ? '*' : '';
            $fitness = number_format((float) $p->fitness, 2);
            $lever   = !empty($p->lever['dimension']) ? "→{$p->lever['dimension']}" : '';
            $parts[] = "{$p->name}{$immune}{$lever}({$fitness})";
        }

        return 'Patterns: ' . implode(', ', $parts);
    }

    private function renderFull(BehaviorPattern $p): string
    {
        $lines = [
            "Pattern:  {$p->name}",
            "Status:   {$p->status}" . ($p->immune ? ' [immune]' : ''),
            "Fitness:  " . number_format((float) $p->fitness, 3),
            "Priority: {$p->priority}",
            "Provenance: {$p->provenance}",
            'Trigger:  ' . json_encode($p->trigger, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        if (!empty($p->intent)) {
            $lines[] = "Intent:   {$p->intent}";
        }
        if (!empty($p->behavior)) {
            $lines[] = 'Behavior: ' . json_encode($p->behavior, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (!empty($p->lever)) {
            $lines[] = 'Lever:    ' . json_encode($p->lever, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($p->immune && $p->forced_activation_interval !== null) {
            $lines[] = "Quota:    forced lead every {$p->forced_activation_interval} cycles";
        }

        $lines[] = "Activations: {$p->activation_count}"
            . ($p->last_activation_seq !== null ? " (last at cycle {$p->last_activation_seq})" : '');

        return implode("\n", $lines);
    }

    private function getBehaviorPhilosophy(array $config = []): string
    {
        if (!empty($config['behavior_philosophy'])) {
            return $config['behavior_philosophy'];
        }
        return  'Discover, don\'t invent: notice recurring tendencies, formalize them as hypotheses, let selection reveal what fits.';
    }

}
