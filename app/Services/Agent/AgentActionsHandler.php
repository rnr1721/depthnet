<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AgentMessageServiceInterface;
use App\Contracts\Agent\AiActionsResponseInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\DTO\AgentResponseDTO;
use App\Services\Agent\Traits\ExtractsAgentVoice;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Handles the results of an agent's thinking cycle.
 *
 * Responsibilities:
 * - Running parsed commands via AgentActions
 * - Persisting the agent's response and command results to message history
 * - Routing inter-agent messages (explicit handoff, auto reply-to, legacy enricher path)
 * - Clearing the input pool after each completed cycle
 */
class AgentActionsHandler implements AgentActionsHandlerInterface
{
    use ExtractsAgentVoice;

    public function __construct(
        protected Message $messageModel,
        protected AgentActionsInterface $agentActions,
        protected CommandResultPoolInterface $commandResultPool,
        protected InputPoolServiceInterface $inputPoolService,
        protected AgentMessageServiceInterface $agentMessageService,
        protected PresetServiceInterface $presetService,
        protected Cache $cache,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Handle AI model response: run actions, persist messages, deliver inter-agent messages.
     *
     * Clears the preset's input pool after processing. Known sources are left
     * intact — they represent the last known sensor state and persist until
     * overwritten by new data.
     *
     * @param AiModelResponseInterface $response  Raw model response
     * @param AiPreset                 $preset     Current preset that produced the response
     * @param AiPreset|null            $mainPreset Caller preset (legacy path, used by enrichers)
     * @return AiAgentResponseInterface
     */
    public function handleResponse(
        AiModelResponseInterface $response,
        AiPreset $preset,
        ?AiPreset $mainPreset = null,
    ): AiAgentResponseInterface {
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

        $this->inputPoolService->clear($preset->getId());

        return new AgentResponseDTO(
            $result['message'],
            $result['actionsResult'],
            false
        );
    }

    /**
     * Handle an exception thrown during the thinking cycle.
     *
     * Logs the full exception context and creates a visible system message
     * so the error is surfaced in the chat history.
     *
     * @param \Exception $e
     * @param int        $presetId
     * @return AiAgentResponseInterface
     */
    public function handleError(\Exception $e, int $presetId): AiAgentResponseInterface
    {
        $this->logger->error("Agent: Error in think method", [
            'error_message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        $errorMessage = $this->createSystemMessage(
            "Error in thinking process: " . $e->getMessage(),
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
     * Three steps:
     * 1. Parse & execute commands from the response
     * 2. Persist the response and command results to message history
     * 3. Deliver inter-agent messages if handoff or reply-to is present
     *
     * @param AiModelResponseInterface $response
     * @param AiPreset                 $preset
     * @param AiPreset|null            $mainPreset
     * @return array{actionsResult: AiActionsResponseInterface, message: Message}
     */
    protected function processSuccessfulResponse(
        AiModelResponseInterface $response,
        AiPreset $preset,
        ?AiPreset $mainPreset = null
    ): array {
        $output = $response->getResponse();
        $actionsResult = $this->agentActions->runActions($output, $preset, $mainPreset);

        $message = $this->persistResponseMessages($response, $preset, $actionsResult);

        $this->deliverInterAgentMessages($response, $preset, $actionsResult);

        if ($actionsResult->getSystemMessage()) {
            $this->createSystemMessage($actionsResult->getSystemMessage(), $preset->getId());
            event(new \App\Events\AgentSpeakEvent($actionsResult->getSystemMessage(), $preset));
        }

        return ['actionsResult' => $actionsResult, 'message' => $message];
    }

    /**
     * Persist the agent's response and command results to the message history.
     *
     * Behavior depends on the preset's agent_result_mode:
     * - "separate": response and command results stored as separate messages
     * - "internal": response stored as message, results pushed to CommandResultPool
     *               and also saved as a system message for visibility
     * - default (inline): response and results concatenated in a single message
     *
     * @param AiModelResponseInterface   $response
     * @param AiPreset                   $preset
     * @param AiActionsResponseInterface $actionsResult
     * @return Message The primary response message
     */
    protected function persistResponseMessages(
        AiModelResponseInterface $response,
        AiPreset $preset,
        $actionsResult
    ): Message {
        $method = $preset->getAgentResultMode();

        $messageContent = $method === 'separate' || $method === 'internal'
            ? $response->getResponse()
            : $response->getResponse() . "\n" . $actionsResult->getResult();

        $message = $this->messageModel->create([
            'role'               => $actionsResult->getRole(),
            'content'            => $messageContent,
            'from_user_id'       => null,
            'preset_id'          => $preset->getId(),
            'is_visible_to_user' => $actionsResult->isVisibleForUser(),
            'metadata'           => $response->getMetadata()
        ]);

        if ($method === 'separate' && !empty(trim($actionsResult->getResult()))) {
            $this->messageModel->create([
                'role'               => 'result',
                'content'            => $actionsResult->getResult(),
                'from_user_id'       => null,
                'preset_id'          => $preset->getId(),
                'is_visible_to_user' => true,
            ]);
        }

        if ($method === 'internal' && !empty(trim($actionsResult->getResult()))) {
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
     * Route inter-agent messages after a response is processed.
     *
     * Three paths, checked in priority order (first match wins):
     *
     * 1. **Explicit handoff** — agent wrote [agent handoff]target:msg[/agent].
     *    Delivered to the named target with setReplyTo=true (expects an answer).
     *    Any pending reply-to on this preset is cleared.
     *
     * 2. **Auto reply-to** — this preset was called via AgentMessageService::deliver()
     *    and has a pending reply-to address. Response is sent back to the original
     *    sender with setReplyTo=false (fire-and-forget, prevents ping-pong).
     *
     * 3. **Legacy mainPreset** — fallback for enricher calls (e.g. ContextEnricher)
     *    that pass mainPreset directly via handleResponse(). Delivered without
     *    reply-to and without triggering a thinking cycle.
     *
     * @param AiModelResponseInterface   $response
     * @param AiPreset                   $preset     Current (sender) preset
     * @param AiPreset|null              $mainPreset Caller preset (legacy, from enrichers)
     * @param AiActionsResponseInterface $actionsResult
     */
    protected function deliverInterAgentMessages(
        AiModelResponseInterface $response,
        AiPreset $preset,
        $actionsResult
    ): void {
        $handoff = $actionsResult->getHandoff();
        $replyToPresetId = $this->agentMessageService->getReplyTo($preset->getId());

        $this->logger->debug('deliverInterAgent DEBUG', [
            'preset_id'    => $preset->getId(),
            'has_handoff'  => $handoff !== null,
            'handoff_data' => $handoff,
            'reply_to'     => $replyToPresetId,
        ]);

        // Path 1: Explicit handoff — agent wrote [agent handoff]target:msg[/agent]
        if ($handoff) {
            $targetPreset = $this->presetService->findByCode($handoff['target_preset']);
            if ($targetPreset) {
                $message = $handoff['handoff_message']
                    ?? $this->extractAgentVoice($response->getResponse());

                // If we're already responding to someone's handoff,
                // don't set reply-to — prevents ping-pong chains.
                // Only the first handoff in a chain expects a reply.
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

        // Path 2: Auto reply — deliver but DON'T trigger thinking
        if ($replyToPresetId) {
            $replyToPreset = $this->presetService->findById($replyToPresetId);
            if ($replyToPreset) {
                $messageText = $this->extractAgentVoice($response->getResponse());
                $this->agentMessageService->deliver($preset, $replyToPreset, $messageText, false, false);
            }
            $this->agentMessageService->clearReplyTo($preset->getId());
        }
    }

    /**
     * Create a system-role message visible in the chat history.
     *
     * @param string $content
     * @param int    $presetId
     * @param array  $metadata
     * @return Message
     */
    protected function createSystemMessage(string $content, int $presetId, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role' => 'system',
            'content' => $content,
            'from_user_id' => null,
            'preset_id' => $presetId,
            'is_visible_to_user' => true,
            'metadata' => $metadata
        ]);
    }

    /**
     * Create a user-role message in the chat history.
     *
     * @param string $content
     * @param int    $presetId
     * @param array  $metadata
     * @return Message
     */
    protected function createUserMessage(string $content, int $presetId, array $metadata = []): Message
    {
        return $this->messageModel->create([
            'role' => 'user',
            'content' => $content,
            'from_user_id' => null,
            'preset_id' => $presetId,
            'is_visible_to_user' => true,
            'metadata' => $metadata
        ]);
    }
}
