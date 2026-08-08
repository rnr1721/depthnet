<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AgentInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Behavior\BehaviorCoordinatorInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\CommandPreRunnerInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\Compaction\CompactionServiceInterface;
use App\Contracts\Agent\ContextBuilder\ContextBuilderFactoryInterface;
use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\PluginRegistryInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Contracts\Agent\ToolSchemaBuilderInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\DTO\ModelRequestDTO;
use App\Services\Agent\Plugins\CompactPlugin;
use App\Services\Agent\Plugins\ReflectPlugin;
use Psr\Log\LoggerInterface;

/**
 * Core agent that orchestrates a single thinking cycle.
 *
 * Responsibilities:
 *   1. Prepare the preset environment (apply plugins, register shortcodes, run pre-commands)
 *   2. Build the conversation context for the current cycle
 *   3. Generate an AI response via the preset's engine
 *   4. Delegate response handling (command execution, persistence, inter-agent routing)
 *      to AgentActionsHandler
 *
 * The agent is stateless between cycles — all state lives in the database,
 * cache, and plugin metadata stores. Each call to think() is independent.
 *
 * Tool_calls mode (agent_result_mode = 'tool_calls'):
 *   A single flag that controls the entire tool_calls pipeline:
 *     - tools array is built and attached to the model request
 *     - [[command_instructions]] shortcode is suppressed (empty string) at
 *       preset scope — the model learns about available tools through the
 *       tools array sent to the API, not through tag syntax in the prompt
 *     - ToolCallParser is used instead of CommandParserSmart
 *     - history is stored in assistant/tool turn format
 *
 * Pre-pass ("reasoning"):
 *   An optional extra generation over the SAME assembled context, run BEFORE the
 *   speaking pass. Same preset, same system prompt, same RAG/inner_voice — only
 *   the trailing user turn is swapped for the preset's pre_pass_instruction. The
 *   pre-pass output is exposed to the speaking pass via the [[reasoning]]
 *   placeholder and is ephemeral: never persisted, regenerated fresh each cycle.
 *
 *   Two activation paths (see shouldRunPrePass):
 *     - always-on: preset->getPrePassEnabled() — think before every utterance.
 *     - on-demand: ReflectPlugin sets a one-shot flag; the agent decides per cycle.
 *
 *   The character of the reasoning is defined entirely by pre_pass_instruction,
 *   not by this class — analytical, exploratory, deliberative, pre-verbal, etc.
 */
class Agent implements AgentInterface
{
    private const MODE_CYCLE  = 'cycle';
    private const MODE_SINGLE = 'single';

    /**
     * Pause between the pre-pass and the speaking pass, in seconds.
     * Two back-to-back generations would hit the provider in one tick and risk
     * a rate-limit on the pass that actually matters. Cheap insurance. Runs in a
     * queue worker (ProcessAgentThinking), so blocking briefly is acceptable.
     */
    private const PRE_PASS_COOLDOWN_SECONDS = 3;

    public function __construct(
        protected PresetRegistryInterface $presetRegistry,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected CommandPreRunnerInterface $commandPreRunner,
        protected AgentActionsHandlerInterface $agentActionsHandler,
        protected MemoryServiceInterface $memoryService,
        protected ShortcodeManagerServiceInterface $shortcodeManagerService,
        protected PluginRegistryInterface $pluginRegistry,
        protected ContextBuilderFactoryInterface $contextBuilderFactory,
        protected ChatStatusServiceInterface $chatStatusService,
        protected PluginMetadataServiceInterface $pluginMetadataService,
        protected CommandResultPoolInterface $commandResultPool,
        protected ToolSchemaBuilderInterface $toolSchemaBuilder,
        protected ContextModeResolverInterface $contextModeResolver,
        protected LoggerInterface $logger,
        protected ?BehaviorCoordinatorInterface $behavior = null,
        protected ?CompactionServiceInterface $compaction = null,
    ) {
    }

    /**
     * Execute a single thinking cycle for the given preset.
     *
     * @param  AiPreset              $currentPreset
     * @return AiAgentResponseInterface
     */
    public function think(AiPreset $currentPreset): AiAgentResponseInterface
    {
        $presetId = $currentPreset->getId();
        try {
            $this->setupPresetEnvironment($currentPreset);

            // Consolidate BEFORE assembling context: an agent-armed [compact],
            // or the watchdog when the window has grown too large. Either writes
            // the recap and folds the range, so buildContext() below sees the
            // fresh, post-fold window with the recap as its trailing turn.
            $this->maybeCompact($currentPreset);

            $context  = $this->buildContext($currentPreset);
            $response = $this->generateResponse($context, $currentPreset);
            $result   = $this->agentActionsHandler->handleResponse($response, $currentPreset);

            return $result;
        } catch (\Exception $e) {
            return $this->agentActionsHandler->handleError($e, $presetId);
        }
    }

    /**
     * Build the conversation context for the current thinking cycle.
     *
     * @param  AiPreset $preset
     * @return array
     */
    protected function buildContext(AiPreset $preset): array
    {
        $mode           = $this->chatStatusService->getChatStatus() ? self::MODE_CYCLE : self::MODE_SINGLE;
        $contextBuilder = $this->contextBuilderFactory->getContextBuilder($mode);

        return $contextBuilder->build($preset);
    }

    /**
     * Generate an AI response using the preset's configured engine.
     *
     * When agent_result_mode = 'tool_calls', ToolSchemaBuilder assembles
     * OpenAI-compatible tool schemas from all enabled plugins and attaches
     * them to the request via additionalParams['tools']. The engine forwards
     * them to the provider API.
     *
     * In all other modes no tools are attached — the model uses tag syntax
     * described in the system prompt via [[command_instructions]].
     *
     * When a pre-pass is due (see shouldRunPrePass), it runs FIRST over the same
     * context, and its output is registered as [[reasoning]] before the speaking
     * pass below reads it.
     *
     * @param  array    $context
     * @param  AiPreset $preset
     * @return AiModelResponseInterface
     */
    protected function generateResponse(array $context, AiPreset $preset): AiModelResponseInterface
    {
        $currentEngine    = $this->presetRegistry->createInstance($preset->getId());
        $additionalParams = [];

        if ($preset->getAgentResultMode() === 'tool_calls') {
            $additionalParams['tools'] = $this->toolSchemaBuilder->buildForPreset($preset);
        }

        if ($this->shouldRunPrePass($preset)) {
            $this->runPrePass($currentEngine, $context, $preset);
        }

        return $currentEngine->generate(
            new ModelRequestDTO(
                $preset,
                $this->memoryService,
                $this->commandInstructionBuilder,
                $this->shortcodeManagerService,
                $this->pluginMetadataService,
                $context,
                $additionalParams
            )
        );
    }

    /**
     * Run a compaction pass this cycle when either trigger fires:
     *
     *   agent-driven — CompactPlugin armed a one-shot flag last cycle (the
     *                  primary, semantic trigger: the agent decided a boundary
     *                  was reached). Consumed read-once, like the pre-pass flag.
     *   watchdog     — the active window has grown past the preset's
     *                  compaction_watchdog_limit (the safety net for when the
     *                  agent never calls [compact] itself).
     *
     * No-op when compaction isn't wired (service absent or no compressor preset).
     * The agent flag wins over the watchdog — if both would fire, the explicit
     * agent focus is honoured and the watchdog check is moot (the window shrinks).
     */
    protected function maybeCompact(AiPreset $preset): void
    {
        if ($this->compaction === null || !$preset->hasCompaction()) {
            return;
        }

        // 1) Agent-driven one-shot flag (consume read-once, like the pre-pass).
        $pending = $this->pluginMetadataService->get(
            $preset,
            CompactPlugin::PLUGIN_NAME,
            CompactPlugin::META_PENDING,
            false
        );

        if ($pending) {
            $this->pluginMetadataService->remove(
                $preset,
                CompactPlugin::PLUGIN_NAME,
                CompactPlugin::META_PENDING
            );

            $focus = $this->pluginMetadataService->get(
                $preset,
                CompactPlugin::PLUGIN_NAME,
                CompactPlugin::META_FOCUS,
                null
            );

            if ($focus !== null) {
                $this->pluginMetadataService->remove(
                    $preset,
                    CompactPlugin::PLUGIN_NAME,
                    CompactPlugin::META_FOCUS
                );
            }

            $this->compaction->compact($preset, is_string($focus) ? $focus : null);
            return; // agent trigger handled — don't also watchdog this cycle
        }

        // 2) Watchdog: fold when the active window outgrows the CURRENT MODE's
        // context limit by more than the configured slack. Critically, the
        // threshold is relative to the mode's context limit (normal vs extended),
        // NOT an absolute count — otherwise the watchdog fires in extended (work)
        // mode before the window ever reaches its larger extended ceiling, folding
        // an instrumental agent mid-task. compaction_watchdog_limit is read as the
        // SLACK above the mode limit: fold once activeWindow >= modeLimit + slack.
        // 0/null slack disables the watchdog (agent-driven [compact] still works).
        $slack = $preset->getCompactionWatchdogLimit();
        if ($slack === null) {
            return; // watchdog off
        }

        $modeLimit   = $this->contextModeResolver->activeContextLimit($preset);
        $threshold   = $modeLimit + $slack;
        $activeCount = $this->compaction->activeWindowCount($preset);

        if ($activeCount >= $threshold) {
            $this->logger->info('Agent: watchdog compaction triggered', [
                'preset_id'    => $preset->getId(),
                'active_count' => $activeCount,
                'mode_limit'   => $modeLimit,
                'slack'        => $slack,
                'threshold'    => $threshold,
            ]);
            // No focus — the watchdog fold follows the mode-derived profile.
            $this->compaction->compact($preset, null);
        }
    }

    /**
     * Decide whether the pre-pass should run this cycle.
     *
     * Two independent activation paths:
     *
     *   always-on — preset->getPrePassEnabled() is true. The operator opted the
     *               preset into thinking before every utterance.
     *
     *   on-demand — the box is off, but ReflectPlugin set a one-shot flag last
     *               cycle. Enabling that plugin IS the opt-in: activation
     *               responsibility sits with the model, which decides per cycle.
     *               The flag is consumed read-once (memo discipline) so the dive
     *               happens this cycle and only this cycle.
     *
     * @param  AiPreset $preset
     * @return bool
     */
    protected function shouldRunPrePass(AiPreset $preset): bool
    {
        // Always-on: think before every utterance.
        if ($preset->getPrePassEnabled()) {
            return true;
        }

        // On-demand: consume the one-shot flag set by ReflectPlugin last cycle.
        $pending = $this->pluginMetadataService->get(
            $preset,
            ReflectPlugin::PLUGIN_NAME,
            ReflectPlugin::META_PENDING,
            false
        );

        if ($pending) {
            $this->pluginMetadataService->remove(
                $preset,
                ReflectPlugin::PLUGIN_NAME,
                ReflectPlugin::META_PENDING
            );
            return true;
        }

        return false;
    }

    /**
     * Run the pre-pass and register its output as [[reasoning]] for the
     * speaking pass.
     *
     * The pre-pass sees the identical context except the final user turn, which
     * is replaced by the preset's pre_pass_instruction (optionally augmented with
     * an on-demand focus note from ReflectPlugin). No tools are attached — we want
     * text out of this pass, not tool calls. The result is registered as a
     * preset-scoped [[reasoning]] shortcode, overriding the global empty stub for
     * the duration of this cycle's speaking pass.
     *
     * Failures are swallowed (logged): a broken pre-pass must never block the
     * speaking pass. On failure [[reasoning]] stays empty and the agent speaks as
     * if the pre-pass were off.
     *
     * @param  mixed    $engine   The preset's engine instance (AIModelEngineInterface)
     * @param  array    $context  The fully assembled cycle context
     * @param  AiPreset $preset
     * @return void
     */
    protected function runPrePass($engine, array $context, AiPreset $preset): void
    {
        try {
            $instruction = trim((string) $preset->getPrePassInstruction());

            // On-demand dives may carry a focus — the content the agent passed to
            // [reflect]. Appended, not substituted: the base instruction sets the
            // frame, the focus just points it. Consumed read-once.
            $focus = $this->pluginMetadataService->get(
                $preset,
                ReflectPlugin::PLUGIN_NAME,
                ReflectPlugin::META_FOCUS,
                null
            );

            if (!empty($focus)) {
                $this->pluginMetadataService->remove(
                    $preset,
                    ReflectPlugin::PLUGIN_NAME,
                    ReflectPlugin::META_FOCUS
                );
                $instruction = trim($instruction . "\n\nThis time, focus on: " . trim((string) $focus));
            }

            // Flag/plugin on but no instruction configured — nothing meaningful to
            // ask. Skip rather than burn a generation on an empty prompt.
            if ($instruction === '') {
                $this->logger->warning('Agent: pre-pass due but instruction is empty — skipping', [
                    'preset_id' => $preset->getId(),
                ]);
                return;
            }

            $preContext = $this->swapTrailingUserTurn($context, $instruction);

            $response = $engine->generate(
                new ModelRequestDTO(
                    $preset,
                    $this->memoryService,
                    $this->commandInstructionBuilder,
                    $this->shortcodeManagerService,
                    $this->pluginMetadataService,
                    $preContext,
                    [] // no tools — pre-pass produces text, not tool calls
                )
            );

            if ($response->isError()) {
                $this->logger->warning('Agent: pre-pass returned an error — speaking without reasoning', [
                    'preset_id' => $preset->getId(),
                    'error'     => $response->getResponse(),
                ]);
                return;
            }

            $reasoning = trim($response->getResponse());

            // Override the global empty [[reasoning]] stub for this cycle's
            // speaking pass. Re-registration overwrites cleanly (PlaceholderService).
            $this->shortcodeManagerService->registerShortcodeForPreset(
                $preset->getId(),
                'reasoning',
                'The result of the extra reasoning pass run over the full context this cycle',
                fn () => $reasoning
            );

            // Breathe before the speaking pass.
            sleep(self::PRE_PASS_COOLDOWN_SECONDS);

        } catch (\Throwable $e) {
            $this->logger->error('Agent: pre-pass failed — speaking without reasoning', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Return a copy of $context with the trailing user turn's content replaced by
     * $instruction. If the last turn is not a user turn (shouldn't happen — the
     * context builders guarantee a trailing user message), append one instead.
     *
     * The original $context is never mutated — the speaking pass must see the real
     * trailing turn; only the pre-pass sees the instruction.
     *
     * @param  array  $context
     * @param  string $instruction
     * @return array
     */
    protected function swapTrailingUserTurn(array $context, string $instruction): array
    {
        if (empty($context)) {
            return [[
                'role'         => 'user',
                'content'      => $instruction,
                'from_user_id' => null,
            ]];
        }

        $lastKey = array_key_last($context);

        if (($context[$lastKey]['role'] ?? null) === 'user') {
            $context[$lastKey]['content'] = $instruction;
        } else {
            $context[] = [
                'role'         => 'user',
                'content'      => $instruction,
                'from_user_id' => null,
            ];
        }

        return $context;
    }

    /**
     * Prepare the preset environment before the thinking cycle begins.
     *
     * Steps:
     *   1. Apply preset-specific plugin configuration via PluginRegistry
     *   2. Register default shortcodes (datetime, dopamine, etc.)
     *   3. In tool_calls mode: suppress [[command_instructions]] at preset scope.
     *      The global shortcode returns tag syntax documentation which is irrelevant
     *      when the model uses native tool_calls. The model learns about available
     *      tools through the tools array in the API request instead.
     *   4. In internal mode: register [[agent_command_results]] shortcode
     *   5. Execute pre-run commands and expose results via [[pre_command_results]]
     *
     * @param  AiPreset $preset
     * @return void
     */
    protected function setupPresetEnvironment(AiPreset $preset): void
    {
        $this->pluginRegistry->applyPreset($preset);
        $this->shortcodeManagerService->setDefaultShortcodes($preset);

        if ($preset->getAgentResultMode() === 'internal') {
            $this->shortcodeManagerService->registerShortcodeForPreset(
                $preset->getId(),
                'agent_command_results',
                '',
                fn () => $this->commandResultPool->getFormatted($preset)
            );
        }

        $this->shortcodeManagerService->registerShortcodeForPreset(
            $preset->getId(),
            'context_mode',
            'Current cognitive context mode: normal or extended',
            fn () => $this->contextModeResolver->activeMode($preset)
        );

        $memo = $this->pluginMetadataService->get($preset, 'memo', 'self_system_note', null);
        if ($memo && is_string($memo)) {
            // Consume immediately and deterministically — read once, delete once.
            // Putting remove() inside the resolver would tie deletion to placeholder
            // presence in the prompt, which is a user-editable concern. The note
            // should be consumed at the start of every cycle that finds it pending,
            // regardless of whether [[memo]] appears in the rendered prompt.
            $this->pluginMetadataService->remove($preset, 'memo', 'self_system_note');

            $this->shortcodeManagerService->registerShortcodeForPreset(
                $preset->getId(),
                'memo',
                '',
                fn () => $memo
            );
        }

        // ── ABS: select the dominant pattern for this cycle ─────────────────────
        // No-op when ABS is inactive for this preset. Runs before generation so the
        // winner's behavior can shape the speaking pass via [[behavior]].
        $this->registerBehaviorShortcode($preset);


        $this->commandPreRunner->run($preset, $preset);
    }

    /**
     * Run ABS selection for this cycle and expose the dominant pattern's
     * behavior to the speaking pass via the [[behavior]] placeholder.
     *
     * Mirrors how [[reasoning]] and [[memo]] are registered: a preset-scoped
     * shortcode overriding a global empty stub for this cycle only. When ABS is
     * off, or nothing was selected, [[behavior]] stays empty and the agent speaks
     * exactly as it does today.
     *
     * The behavior text is intentionally a SOFT INFLUENCE, not a command: phase 1
     * exposes the winning pattern's `intent` (and any `behavior` descriptor) as
     * context the model reads, not as an instruction it must obey. Enacting
     * through plugins/handoff — turning a pattern into a forced action — is a
     * later step, deliberately deferred so the first live run observes whether
     * selection produces COHERENT pressure before we let it pull levers.
     */
    private function registerBehaviorShortcode(AiPreset $preset): void
    {
        if ($this->behavior === null) {
            return;
        }

        $selection = $this->behavior->openCycle($preset); // advances seq, records activation
        if ($selection === null) {
            return; // ABS inactive
        }

        $dominant = $selection->dominant();
        if ($dominant === null) {
            return; // nothing triggered, no quota due — leave [[behavior]] empty
        }

        // Build the soft-influence text. intent is the human/agent-readable goal;
        // behavior{} may carry a descriptor the speaking pass can lean on. Forced
        // (reservation) cycles are marked so the prompt can frame them as a
        // deliberate turn toward protected behavior, not a competitive win.
        $text = $this->composeBehaviorText($dominant, $selection->isForced());

        $this->shortcodeManagerService->registerShortcodeForPreset(
            $preset->getId(),
            'behavior',
            'The behavior pattern selected for this cycle (soft influence on the response)',
            fn () => $text
        );
    }

    /**
     * Render the dominant pattern as the [[behavior]] text. Kept small and
     * declarative — no model call. A forced cycle gets a gentle framing so the
     * agent experiences it as turning toward what it protects, per the
     * reservation's spirit ("lived, not logged").
     */
    private function composeBehaviorText(\App\Models\BehaviorPattern $p, bool $forced): string
    {
        $intent = trim((string) ($p->intent ?? ''));
        $lines = [];

        if ($forced) {
            $lines[] = 'This cycle turns toward a protected way of being.';
        }

        if ($intent !== '') {
            $lines[] = $intent;
        }

        // behavior{} is a loose descriptor in phase 1; surface a hint if present.
        $descriptor = $p->behavior['hint'] ?? ($p->behavior['descriptor'] ?? null);
        if (is_string($descriptor) && trim($descriptor) !== '') {
            $lines[] = trim($descriptor);
        }

        return implode("\n", $lines);
    }


}
