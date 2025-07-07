<?php

namespace App\Services\Agent\DTO;

use App\Contracts\Agent\AiActionsResponseInterface;
use App\Contracts\Agent\AiAgentResponseInterface;
use App\Models\Message;

class AgentResponseDTO implements AiAgentResponseInterface
{
    public function __construct(
        private Message $message,
        private AiActionsResponseInterface $actionsResult,
        private bool $hasError = false,
        private ?string $errorMessage = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getMessage(): Message
    {
        return $this->message;
    }

    /**
     * @inheritDoc
     */
    public function getActionsResult(): AiActionsResponseInterface
    {
        return $this->actionsResult;
    }

    /**
     * @inheritDoc
     */
    public function hasHandoff(): bool
    {
        return $this->actionsResult->getHandoff() !== null;
    }

    /**
     * @inheritDoc
     */
    public function getHandoffData(): ?array
    {
        return $this->actionsResult->getHandoff();
    }

    /**
     * @inheritDoc
     */
    public function hasError(): bool
    {
        return $this->hasError;
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
