<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\AgentActionsHandlerInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Contracts\Agent\CommandResultPoolInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\DTO\ActionsResponseDTO;
use App\Services\Agent\DTO\AgentResponseDTO;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Cache\Repository as Cache;

class AgentActionsHandler implements AgentActionsHandlerInterface
{
    public function __construct(
        protected Message $messageModel,
        protected AgentActionsInterface $agentActions,
        protected CommandResultPoolInterface $commandResultPool,
        protected Cache $cache,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handleResponse(
        $response,
        AiPreset $preset
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

        $result = $this->processSuccessfulResponse($response, $preset);

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
     * @param mixed $response
     * @param AiPreset $preset
     * @return array
     */
    protected function processSuccessfulResponse($response, AiPreset $preset): array
    {

        $output = $response->getResponse();
        $actionsResult = $this->agentActions->runActions($output, $preset);

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

}
