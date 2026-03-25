<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\DTO\AgentResponseDTO;
use App\Services\Agent\Traits\ExtractsAgentVoice;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

class AgentActionsHandler implements AgentActionsHandlerInterface
{
    use ExtractsAgentVoice;

    public function __construct(
        protected Message $messageModel,
        protected AgentActionsInterface $agentActions,
        protected CommandResultPoolInterface $commandResultPool,
        protected InputPoolServiceInterface $inputPoolService,
        protected OptionsServiceInterface $optionsService,
        protected Cache $cache,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
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

        // Clear regular pool items now that the cycle is complete.
        // Known sources are left intact — they represent the last known
        // sensor state and should persist until overwritten by new data.
        //if (!$mainPreset) {
        $this->inputPoolService->clear($preset->getId());
        //}

        return new AgentResponseDTO(
            $result['message'],
            $result['actionsResult'],
            false
        );
    }

    /**
     * @inheritDoc
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
            true, // hasError
            $e->getMessage()
        );
    }

    /**
     * Process successful AI response through actions
     *
     * @param AiModelResponseInterface $response
     * @param AiPreset $preset
     * @param AiPreset|null $mainPreset
     * @return array
     */
    protected function processSuccessfulResponse(AiModelResponseInterface $response, AiPreset $preset, ?AiPreset $mainPreset = null): array
    {

        $output = $response->getResponse();
        $actionsResult = $this->agentActions->runActions($output, $preset, $mainPreset);

        $method = $preset->getAgentResultMode();

        $messageContent = $method === 'separate' || $method === 'internal' ? $response->getResponse() : $response->getResponse() . "\n" . $actionsResult->getResult();

        $message = $this->messageModel->create([
            'role' => $actionsResult->getRole(),
            'content' => $messageContent,
            'from_user_id' => null,
            'preset_id' => $preset->getId(),
            'is_visible_to_user' => $actionsResult->isVisibleForUser(),
            'metadata' => $response->getMetadata()
        ]);

        if ($method === 'separate' && !empty(trim($actionsResult->getResult()))) {
            $this->messageModel->create([
                'role' => 'result',
                'content' => $actionsResult->getResult(),
                'from_user_id' => null,
                'preset_id' => $preset->getId(),
                'is_visible_to_user' => true,
            ]);
        }

        if ($method === 'internal' && !empty(trim($actionsResult->getResult()))) {
            $this->commandResultPool->push($preset, $message, $actionsResult->getResult());
            $this->messageModel->create([
                'role' => 'system',
                'content' => $actionsResult->getResult(),
                'from_user_id' => null,
                'preset_id' => $preset->getId(),
                'is_visible_to_user' => true,
            ]);
        }

        if ($mainPreset && $preset->getId() !== $mainPreset->getId()) {
            $messageText = $this->extractAgentVoice($response->getResponse());
            if ($this->inputPoolService->isEnabled($mainPreset)) {
                $this->inputPoolService->add($mainPreset->getId(), $preset->getAvailableName(), $messageText);
                $messageContent = $this->inputPoolService->getAllAsJSON($mainPreset->getId());
            } else {
                $messageFromUserLabel = 'handoff response from ';
                $messageContent = "$messageFromUserLabel {$preset->getAvailableName()}:\n$messageText";
                $this->createUserMessage($messageContent, $mainPreset->getId());
            }
        }

        if ($actionsResult->getSystemMessage()) {
            $this->createSystemMessage(
                $actionsResult->getSystemMessage(),
                $preset->getId()
            );
        }

        return [
            'actionsResult' => $actionsResult,
            'message' => $message
        ];

    }

    /**
     * Create system message
     *
     * @param string $content
     * @param int $presetId
     * @param array $metadata
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
     * Create user message
     *
     * @param string $content
     * @param int $presetId
     * @param array $metadata
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
