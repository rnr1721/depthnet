<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\AgentMessageServiceInterface;
use App\Contracts\Agent\AiActionsResponseInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Behavior\BehaviorCoordinatorInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\ContextModeResolverInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentTaskServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\Behavior\OutcomeSignal;
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\DTO\AgentResponseDTO;
use App\Services\Chat\ChatStatusService;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Handles the results of an agent's thinking cycle.
 *
 * Responsibilities:
 * - Running parsed commands via AgentActions
 * - Persisting the agent's response and command results to message history
 * - Routing inter-agent messages (explicit handoff, auto reply-to)
 * - Clearing the input pool after each completed cycle
 *
 * Persistence modes (controlled by preset's agent_result_mode):
 *   "internal"   — results pushed to CommandResultPool and injected via
 *                  [[agent_command_results]] placeholder (default, recommended
 *                  for autonomous agents)
 *   "tool_calls" — full tool_calls pipeline: ToolCallParser parses the response,
 *                  tools array is sent to the provider API, history is stored in
 *                  assistant/tool turn format required by provider APIs.
 *
 * Note: "inline" mode has been removed. It caused models to hallucinate
 * command results as part of their own output in subsequent cycles.
 *
 * Orchestrated pipeline mode (determineTurnNeed):
 *   A single rule governs every orchestrated participant — each self-continues
 *   until it emits its own terminal signal:
 *     role/executor → task leaves IN_PROGRESS (via [task done]/[task fail])
 *     validator     → task leaves VALIDATING (via [task approve]/[task reject])
 *     planner       → [task commit], or it speaks to the user, or it stalls
 *   The orchestrated marker (metadata['source'] = orchestrator) is set on the
 *   first cycle by the orchestrator and inherited onto the self-continue
 *   "Continue" message so the whole run stays in pipeline mode. turn_trigger is
 *   ignored while orchestrated, keeping normal chat/voice/api behaviour intact
 *   for the same preset (e.g. when debugged directly in chat).
 *
 *   Planner stall guard: a planner that self-continues without any productive
 *   action (task creation, commit, or speaking) for PLANNER_STALL_LIMIT cycles
 *   is stopped with a warning rather than looping forever. Productive actions
 *   reset the counter; a normal terminal (commit/speak) clears it.
 */
class AgentActionsHandler implements AgentActionsHandlerInterface
{
    /**
     * Plugin-metadata namespace and key for the planner stall counter.
     * Stored per planner preset, counts consecutive orchestrated self-continue
     * cycles with no productive action.
     */
    private const PLANNER_META_PLUGIN   = 'orchestrator_planner';
    private const PLANNER_ITER_KEY      = 'idle_iterations';

    /**
     * Maximum orchestrated self-continue cycles a planner may run without a
     * productive action before it is force-stopped as stalled.
     */
    private const PLANNER_STALL_LIMIT = 8;

    public function __construct(
        protected Message $messageModel,
        protected AgentJobServiceFactoryInterface $agentJobFactory,
        protected AgentActionsInterface $agentActions,
        protected CommandResultPoolInterface $commandResultPool,
        protected InputPoolServiceInterface $inputPoolService,
        protected AgentMessageServiceInterface $agentMessageService,
        protected PresetServiceInterface $presetService,
        protected ChatStatusService $chatStatusService,
        protected Cache $cache,
        protected LoggerInterface $logger,
        protected ContextModeResolverInterface $contextModeResolver,
        protected AgentTaskServiceInterface $agentTaskService,
        protected PluginMetadataServiceInterface $pluginMetadataService,
        protected ?BehaviorCoordinatorInterface $behavior = null,
    ) {
    }

    /**
     * Handle AI model response: run actions, persist messages, deliver inter-agent messages.
     *
     * Clears the preset's input pool after processing. Known sources are left
     * intact — they represent the last known sensor state and persist until
     * overwritten by new data.
     *
     * @param  AiModelResponseInterface $response
     * @param  AiPreset                 $preset
     * @param  AiPreset|null            $mainPreset
     * @return AiAgentResponseInterface
     */
    public function handleResponse(
        AiModelResponseInterface $response,
        AiPreset $preset,
        ?AiPreset $mainPreset = null,
    ): AiAgentResponseInterface {
        // Read the cycle's origin BEFORE any service messages (Continue, pool
        // remnants) are written below — otherwise "last user message" would point
        // at our own bookkeeping, not at what actually woke this cycle.
        $orchestrated = $this->cycleWasOrchestrated($preset);

        if ($response->isError()) {
            $errorMessage = $this->createSystemMessage(
                $response->getResponse(),
                $preset->getId(),
                $response->getMetadata()
            );

            return new AgentResponseDTO(
                $errorMessage,
                new ActionsResponseDTO('', 'system', false, true),
                true,
                $response->getResponse()
            );
        }

        $result = $this->processSuccessfulResponse($response, $preset, $mainPreset);

        $actionsResult = $result['actionsResult'];

        $turn = $this->determineTurnNeed($preset, $actionsResult, $orchestrated);

        // ── ABS hook (no-op when ABS inactive for this preset) ──────────────────
        // Runs AFTER turn detection so it only observes — never alters — the
        // existing pipeline. One cycle = one seq advance = one credit pass.
        if ($this->behavior !== null && $this->behavior->isActive($preset)) {
            $outcome = $this->outcomeForCycle($preset, $actionsResult, $orchestrated);
            $this->behavior->closeCycle($preset, $outcome);
        }

        if ($turn) {
            if ($this->inputPoolService->isEnabled($preset)) {
                // Pool-mode presets are not used in orchestrated pipelines (the
                // orchestrator writes directly to history, bypassing the pool),
                // so no source inheritance is needed on this branch.
                $this->inputPoolService->add($preset->getId(), 'system', 'Continue');
            } else {
                // Inherit the orchestrated marker onto the self-continue message so
                // the next cycle re-enters pipeline mode. Without this, the next
                // cycle's initiating message would be an unmarked "Continue" and the
                // participant would fall out of the pipeline on its second step.
                $this->createUserMessage(
                    'Continue',
                    $preset->getId(),
                    $orchestrated ? ['source' => Message::SOURCE_ORCHESTRATOR] : []
                );
            }
        }

        if ($this->inputPoolService->isEnabled($preset)) {
            $remaining = $this->inputPoolService->getAllAsJSON($preset);
            if ($remaining) {
                $this->createUserMessage($remaining, $preset->getId());
            }
        }

        $this->inputPoolService->clear($preset->getId());

        if ($turn) {
            $this->agentJobFactory->make()->start($preset->getId(), singleMode: true);
        }

        return new AgentResponseDTO($result['message'], $result['actionsResult'], false);
    }

    /**
     * Whether the cycle now being handled was woken by the orchestrator.
     *
     * Reads the most recent user-role message for this preset and checks its
     * source marker. Called at the very start of handleResponse, before any
     * service messages are written, so "most recent user message" reliably means
     * "the message that initiated this cycle".
     */
    private function cycleWasOrchestrated(AiPreset $preset): bool
    {
        $lastUser = $this->messageModel
            ->forPreset($preset->getId())
            ->where('role', 'user')
            ->orderBy('id', 'desc')
            ->first();

        return $lastUser?->getSource() === Message::SOURCE_ORCHESTRATOR;
    }

    /**
     * Decide whether the cycle should run again.
     *
     * Single rule for every orchestrated participant: self-continue until your
     * own terminal signal. Non-orchestrated cycles fall through to turn_trigger,
     * preserving normal chat/voice/api behaviour (including in-chat debugging of
     * the very same preset).
     *
     * Cost ordering: the cheap checks (background flag, orchestrated marker) gate
     * the DB-touching role/planner checks, so free-running presets (Ada, etc.)
     * never pay for a task lookup.
     *
     *   1. Background loop active → loop schedules itself, no turn.
     *   2. Orchestrated:
     *        role/validator (has active task) → continue while IN_PROGRESS/VALIDATING.
     *        planner                          → continue until commit / speak / stall.
     *   3. Normal mode → turn_trigger as configured.
     */
    private function determineTurnNeed(
        AiPreset $preset,
        AiActionsResponseInterface $actionsResult,
        bool $orchestrated
    ): bool {
        if ($this->chatStatusService->getPresetStatus($preset->getId())) {
            return false;
        }

        if ($orchestrated) {
            // Role / validator: bound to task lifecycle state.
            if ($this->agentTaskService->hasActiveTaskForPreset($preset)) {
                return true;
            }

            // Planner: reactive, self-continues until it ends its round.
            if ($this->agentTaskService->isPlanner($preset)) {
                return $this->determinePlannerTurn($preset, $actionsResult);
            }

            // Orchestrated but neither an active worker nor a planner — nothing to
            // self-continue for (e.g. validator whose task already left VALIDATING,
            // or a role whose task is done). Go idle; the orchestrator re-wakes on
            // the next event.
            return false;
        }

        if ($preset->getTurnTrigger() === 'no_speak' && empty($actionsResult->getSystemMessage())) {
            return true;
        }
        if ($actionsResult->hasTurn()) {
            return true;
        }
        return false;
    }

    /**
     * Planner self-continue decision with stall guard.
     *
     * Terminal (stop, counter cleared):
     *   - spoke to the user (system message present)
     *   - committed the round ([task commit])
     * Productive but not terminal (continue, counter reset):
     *   - created a task this cycle — planner may create several before committing
     * Stall guard (stop + warning, counter cleared):
     *   - PLANNER_STALL_LIMIT consecutive cycles with no productive action
     * Otherwise:
     *   - continue, counter incremented
     */
    private function determinePlannerTurn(AiPreset $preset, AiActionsResponseInterface $actionsResult): bool
    {
        $spoke     = !empty(trim((string) $actionsResult->getSystemMessage()));
        $committed = $actionsResult->plannerCommitted();
        $created   = $actionsResult->createdTask();

        // Terminal signals: planner ends its round and goes idle.
        if ($spoke || $committed) {
            $this->resetPlannerCounter($preset);
            return false;
        }

        // Productive (task created) — not terminal, but resets the stall counter:
        // an actively-dispatching planner is not stalling even across many cycles.
        if ($created) {
            $this->resetPlannerCounter($preset);
            return true;
        }

        // No productive action this cycle — advance the stall counter.
        $iterations = (int) $this->pluginMetadataService->get(
            $preset,
            self::PLANNER_META_PLUGIN,
            self::PLANNER_ITER_KEY,
            0
        );
        $iterations++;

        if ($iterations >= self::PLANNER_STALL_LIMIT) {
            $this->logger->warning('AgentActionsHandler: planner stalled — forcing idle', [
                'preset_id'  => $preset->getId(),
                'iterations' => $iterations,
                'limit'      => self::PLANNER_STALL_LIMIT,
            ]);
            // Clear so the next round (woken by orchestrator/user) starts fresh.
            $this->resetPlannerCounter($preset);
            return false;
        }

        $this->pluginMetadataService->set(
            $preset,
            self::PLANNER_META_PLUGIN,
            self::PLANNER_ITER_KEY,
            $iterations
        );

        return true;
    }

    /**
     * Clear the planner stall counter (round ended or reset on productive action).
     */
    private function resetPlannerCounter(AiPreset $preset): void
    {
        if ($this->pluginMetadataService->has($preset, self::PLANNER_META_PLUGIN, self::PLANNER_ITER_KEY)) {
            $this->pluginMetadataService->remove($preset, self::PLANNER_META_PLUGIN, self::PLANNER_ITER_KEY);
        }
    }

    /**
     * Handle an exception thrown during the thinking cycle.
     *
     * @param  \Exception $e
     * @param  int        $presetId
     * @return AiAgentResponseInterface
     */
    public function handleError(\Exception $e, int $presetId): AiAgentResponseInterface
    {
        $this->logger->error('Agent: Error in think method', [
            'error_message' => $e->getMessage(),
            'exception'     => get_class($e),
            'file'          => $e->getFile(),
            'line'          => $e->getLine(),
            'trace'         => $e->getTraceAsString(),
        ]);

        $errorMessage = $this->createSystemMessage(
            'Error in thinking process: ' . $e->getMessage(),
            $presetId
        );

        return new AgentResponseDTO(
            $errorMessage,
            new ActionsResponseDTO('', 'system', false, true),
            true,
            $e->getMessage()
        );
    }

    /**
     * Orchestrate the processing of a successful AI response.
     *
     * @param  AiModelResponseInterface $response
     * @param  AiPreset                 $preset
     * @param  AiPreset|null            $mainPreset
     * @return array{actionsResult: AiActionsResponseInterface, message: Message}
     */
    protected function processSuccessfulResponse(
        AiModelResponseInterface $response,
        AiPreset $preset,
        ?AiPreset $mainPreset = null
    ): array {
        $output        = $response->getResponse();

        $actionsResult = $this->agentActions->runActions($output, $preset, $mainPreset);

        // Advance the context-mode hysteresis by one completed cycle.
        // Done here (successful path only) so a model/provider error freezes the
        // streak rather than pulling the agent out of work mode on a transient
        // failure. No-op when the feature is off (extended_limit unset).
        $this->contextModeResolver->updateStreak(
            $preset,
            $actionsResult->containedLongContextPlugin(),
        );

        $hasSystemMessage = !empty(trim((string) $actionsResult->getSystemMessage()));

        $message       = $this->persistResponseMessages($response, $preset, $actionsResult, $hasSystemMessage);

        $this->deliverInterAgentMessages($response, $preset, $actionsResult);

        if ($hasSystemMessage) {
            $this->createSystemMessage($actionsResult->getSystemMessage(), $preset->getId());
            event(new \App\Events\AgentSpeakEvent($actionsResult->getSystemMessage(), $preset));
        }

        return ['actionsResult' => $actionsResult, 'message' => $message];
    }

    /**
     * Persist the agent's response and command results to the message history.
     *
     * tool_calls is checked first — it is a mandatory protocol requirement
     * and cannot be combined with other persistence modes.
     *
     * @param  AiModelResponseInterface   $response
     * @param  AiPreset                   $preset
     * @param  AiActionsResponseInterface $actionsResult
     * @param  bool                       $hasSystemMessage System message present.
     * @return Message
     */
    protected function persistResponseMessages(
        AiModelResponseInterface $response,
        AiPreset $preset,
        AiActionsResponseInterface $actionsResult,
        bool $hasSystemMessage
    ): Message {
        if ($preset->getAgentResultMode() === 'tool_calls') {
            return $this->persistToolCalls($response, $preset, $actionsResult, $hasSystemMessage);
        }
        return $this->persistInternal($response, $preset, $actionsResult, $hasSystemMessage);
    }

    /**
     * Internal mode (default): results pushed to CommandResultPool.
     *
     * Results are also saved as a visible system message so they appear in the UI,
     * but are injected into the next cycle's system prompt via [[agent_command_results]]
     * rather than the conversation context — prevents models from confusing command
     * output with their own previous responses.
     */
    protected function persistInternal(
        AiModelResponseInterface $response,
        AiPreset $preset,
        AiActionsResponseInterface $actionsResult,
        bool $hasSystemMessage
    ): Message {
        $baseMetadata = array_diff_key($response->getMetadata() ?? [], ['system_prompt' => '']);

        if ($hasSystemMessage) {
            $baseMetadata['content_duplicated_in_system'] = true;
        }

        $message = $this->messageModel->create([
            'role'               => $actionsResult->getRole(),
            'content'            => $response->getResponse(),
            'from_user_id'       => null,
            'preset_id'          => $preset->getId(),
            'is_visible_to_user' => $actionsResult->isVisibleForUser(),
            'metadata'           => $baseMetadata,
            'system_prompt'      => $response->getMetadata()['system_prompt'] ?? null,
        ]);

        if (!empty(trim($actionsResult->getResult()))) {
            $this->commandResultPool->push($preset, $message, $actionsResult->getResult());

            $this->messageModel->create([
                'role'               => 'system',
                'content'            => $actionsResult->getResult(),
                'from_user_id'       => null,
                'preset_id'          => $preset->getId(),
                'is_visible_to_user' => true,
            ]);
        }

        return $message;
    }

    /**
     * Tool-calls mode: persist the assistant/tool turn pair required by provider APIs.
     *
     * Provider APIs require a strict turn sequence when tool_calls are used:
     *
     *   assistant: {tool_calls: [{id: "call_1", name: "memory"}, {id: "call_2", name: "journal"}]}
     *   tool:      {tool_call_id: "call_1", content: "memory result"}
     *   tool:      {tool_call_id: "call_2", content: "journal result"}
     *   assistant: "Final response based on results"
     *
     * Stores tool_calls_raw in metadata of the command message, and a tool_results
     * array (one entry per tool with its id and output) in metadata of the result
     * message. buildMessages() in engines reads these to reconstruct the correct
     * assistant/tool turn sequence.
     *
     * tool_call_id flows exactly: provider response → ToolCallParser →
     * ParsedCommand::toolCallId → CommandResult::toolCallId → here.
     */
    protected function persistToolCalls(
        AiModelResponseInterface $response,
        AiPreset $preset,
        AiActionsResponseInterface $actionsResult,
        bool $hasSystemMessage
    ): Message {
        $rawResponse  = $this->sanitizeForJson($response->getResponse());
        $baseMetadata = $response->getMetadata() ?? [];

        $metadata = array_merge(
            array_diff_key($baseMetadata, ['system_prompt' => '']),
            ['tool_calls_raw' => $rawResponse]
        );

        if ($hasSystemMessage) {
            $metadata['content_duplicated_in_system'] = true;
        }

        // Primary message: assistant turn with tool_calls JSON in metadata.
        $message = $this->messageModel->create([
            'role'               => 'command',
            'content'            => $rawResponse,
            'from_user_id'       => null,
            'preset_id'          => $preset->getId(),
            'is_visible_to_user' => true,
            'system_prompt'      => $baseMetadata['system_prompt'] ?? null,
            'metadata'           => $metadata
        ]);

        $toolResults = $this->buildToolResults($actionsResult);

        if (!empty($toolResults)) {
            $this->messageModel->create([
                'role'               => 'result',
                'content'            => $this->sanitizeForJson($actionsResult->getResult()),
                'from_user_id'       => null,
                'preset_id'          => $preset->getId(),
                'is_visible_to_user' => true,
                'metadata'           => ['tool_results' => $toolResults],
            ]);
        }

        return $message;
    }

    /**
     * Build tool_results array from CommandResult objects.
     *
     * Each entry maps a provider tool_call_id to the plugin's output:
     * [['tool_call_id' => 'call_abc', 'content' => 'result text'], ...]
     *
     * @param  AiActionsResponseInterface $actionsResult
     * @return array
     */
    protected function buildToolResults(AiActionsResponseInterface $actionsResult): array
    {
        $toolResults = [];
        $lastIndexMap = [];

        foreach ($actionsResult->getCommandResults() as $i => $commandResult) {
            if ($commandResult->collapseOutput) {
                $lastIndexMap[$commandResult->command->plugin] = $i;
            }
        }

        foreach ($actionsResult->getCommandResults() as $i => $commandResult) {
            if ($commandResult->toolCallId === null) {
                $this->logger->warning('AgentActionsHandler: CommandResult missing toolCallId in tool_calls mode', [
                    'plugin' => $commandResult->command->plugin,
                    'method' => $commandResult->command->method,
                ]);
                continue;
            }

            $isCollapsed = $commandResult->collapseOutput
                && isset($lastIndexMap[$commandResult->command->plugin])
                && $i !== $lastIndexMap[$commandResult->command->plugin];

            $toolResults[] = [
                'tool_call_id' => $commandResult->toolCallId,
                'content'      => $isCollapsed
                    ? '(output collapsed — see last result)'
                    : $this->sanitizeForJson(
                        $commandResult->success
                            ? $commandResult->result
                            : ('ERROR: ' . $commandResult->error)
                    ),
            ];
        }

        return $toolResults;
    }

    /**
     * Route inter-agent messages after a response is processed.
     *
     * Two paths, checked in priority order:
     *
     * 1. Explicit handoff — agent wrote [agent handoff]target:msg[/agent] or
     *    used AgentPlugin via tool_call. handoff_message is always set explicitly
     *    by the plugin, so extractAgentVoice() is only a fallback for tag mode.
     *
     * 2. Auto reply-to — this preset was called via AgentMessageService::deliver()
     *    and has a pending reply-to address. Response is sent back to the original
     *    sender (fire-and-forget, prevents ping-pong).
     *
     *    In tool_calls mode, $response->getResponse() is a JSON string with tool_calls —
     *    not readable text. We use the formatted command results instead so the
     *    receiving agent gets meaningful content rather than raw JSON.
     *    If there are no results either, delivery is skipped entirely.
     *
     * @param  AiModelResponseInterface   $response
     * @param  AiPreset                   $preset
     * @param  AiActionsResponseInterface $actionsResult
     */
    protected function deliverInterAgentMessages(
        AiModelResponseInterface $response,
        AiPreset $preset,
        AiActionsResponseInterface $actionsResult
    ): void {
        $handoff         = $actionsResult->getHandoff();
        $replyToPresetId = $this->agentMessageService->getReplyTo($preset->getId());

        $this->logger->debug('deliverInterAgent DEBUG', [
            'preset_id'    => $preset->getId(),
            'has_handoff'  => $handoff !== null,
            'handoff_data' => $handoff,
            'reply_to'     => $replyToPresetId,
        ]);

        // Path 1: Explicit handoff
        if ($handoff) {
            $targetPreset = $this->presetService->findByCode($handoff['target_preset']);
            if ($targetPreset) {
                // handoff_message is set by AgentPlugin — either from the tag content
                // or from the tool_call input. extractAgentVoice() is only a fallback
                // for tag mode when no explicit message was provided.
                $message    = $handoff['handoff_message']
                    ?? trim((string) $actionsResult->getSystemMessage());
                $setReplyTo = !$replyToPresetId;
                $this->agentMessageService->deliver($preset, $targetPreset, $message, true, $setReplyTo);
            } else {
                $this->logger->warning('Handoff target not found', [
                    'target' => $handoff['target_preset'],
                    'from'   => $preset->getId(),
                ]);
            }
            $this->agentMessageService->clearReplyTo($preset->getId());
            return;
        }

        // Path 2: Auto reply-to
        if ($replyToPresetId) {
            $replyToPreset = $this->presetService->findById($replyToPresetId);
            if ($replyToPreset) {
                $messageText = trim((string) $actionsResult->getSystemMessage());

                if (!empty($messageText)) {
                    $this->agentMessageService->deliver($preset, $replyToPreset, $messageText, false, false);
                    $this->agentMessageService->clearReplyTo($preset->getId());
                } else {
                    $this->logger->debug('AgentActionsHandler: reply-to pending — agent still thinking', [
                        'preset_id' => $preset->getId(),
                        'reply_to'  => $replyToPresetId,
                    ]);
                }
            } else {
                $this->agentMessageService->clearReplyTo($preset->getId());
            }
        }
    }

    /**
     * Sanitize a string for safe JSON encoding.
     *
     * Strips or replaces invalid UTF-8 sequences that would cause
     * JsonEncodingException when Eloquent tries to encode metadata.
     * This can happen when sandbox command output contains binary data
     * (e.g. reading a Telegram session file or any other binary file).
     *
     * @param  string $value
     * @return string
     */
    protected function sanitizeForJson(string $value): string
    {
        // Replace invalid UTF-8 sequences with the Unicode replacement character
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // mb_convert_encoding may still leave some invalid sequences — strip them
        if (!mb_check_encoding($clean, 'UTF-8')) {
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? $clean;
        }

        return $clean;
    }

    /**
     * @param  string  $content
     * @param  int     $presetId
     * @param  array   $metadata
     * @return Message
     */
    protected function createSystemMessage(string $content, int $presetId, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role'               => 'system',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
            'metadata'           => $metadata,
        ]);
    }

    /**
     * @param  string  $content
     * @param  int     $presetId
     * @param  array   $metadata
     * @return Message
     */
    protected function createUserMessage(string $content, int $presetId, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role'               => 'user',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
            'metadata'           => $metadata,
        ]);
    }

    /**
     * Build this cycle's OutcomeSignal from signals the handler already has.
     * ABS does not recompute outcomes — it reads the same exit conditions turn
     * detection uses. Mapping (phase 1, architect's formalized narrative):
     *
     *   planner committed         → positive('commit',   1.0)
     *   spoke to user             → positive('speak',     0.5)
     *   active task left IN_PROGRESS via [task done]
     *                             → positive('task_done', 1.0)
     *   stall guard would fire    → negative('stall',     1.0)
     *   otherwise                 → none()
     *
     * NUMBERS ARE A NARRATIVE, NOT A FACT. The detection (did the task leave
     * IN_PROGRESS?) is code-checked and unfakeable; the WORTH of each detection
     * is a human choice — declared here, in one place, retunable without touching
     * detection. This is the Goodhart we name and do not hide.
     */
    private function outcomeForCycle(
        AiPreset $preset,
        AiActionsResponseInterface $actionsResult,
        bool $orchestrated
    ): OutcomeSignal {
        // Terminal signals already surfaced on actionsResult:
        if ($actionsResult->plannerCommitted()) {
            return OutcomeSignal::positive('commit', 1.0);
        }

        $spoke = !empty(trim((string) $actionsResult->getSystemMessage()));

        // Task-level outcome: did an active task just leave IN_PROGRESS?
        // agentTaskService already answers hasActiveTaskForPreset(); a task that
        // WAS active last cycle and is no longer is a completion. (Phase-1
        // simplification: we treat "no longer active after a productive cycle" as
        // task_done; refining done-vs-fail uses the task's terminal marker, a
        // small follow-up once the skeleton runs.)
        if ($orchestrated
            && !$this->agentTaskService->hasActiveTaskForPreset($preset)
            && $this->behavior->hadActiveTaskLastCycle($preset)) {
            return OutcomeSignal::positive('task_done', 1.0);
        }

        if ($spoke) {
            return OutcomeSignal::positive('speak', 0.5);
        }

        // Stall is detected inside determinePlannerTurn via the idle counter.
        // To avoid duplicating that state read, the planner-stall outcome is
        // emitted from there through a one-shot flag the coordinator reads; see
        // note below. Default: no creditable outcome this cycle.
        return OutcomeSignal::none();
    }

}
