<?php

namespace App\Services\Agent\DTO;

use App\Contracts\Agent\AiActionsResponseInterface;

/**
 * Actions response DTO, for handling message actions
 */
class ActionsResponseDTO implements AiActionsResponseInterface
{
    public function __construct(
        private string $result = '',
        private string $role = '',
        private bool $isVisibleForUser = false,
        private ?string $systemMessage = null,
        private ?array $handoff = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getResult(): string
    {
        return $this->result;
    }

    /**
     * @inheritDoc
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * @inheritDoc
     */
    public function isVisibleForUser(): bool
    {
        return $this->isVisibleForUser;
    }

    /**
     * @inheritDoc
     */
    public function getSystemMessage(): ?string
    {
        return $this->systemMessage;
    }

    /**
     * @inheritDoc
     */
    public function getHandoff(): ?array
    {
        return $this->handoff;
    }

}
