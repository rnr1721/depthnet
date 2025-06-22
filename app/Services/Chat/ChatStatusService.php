<?php

namespace App\Services\Chat;

use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;

class ChatStatusService implements ChatStatusServiceInterface
{
    public const CHAT_ACTIVE_KEY = 'chat_active';

    public function __construct(
        protected OptionsServiceInterface $optionsService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getChatStatus(): bool
    {
        return $this->optionsService->get(self::CHAT_ACTIVE_KEY, false);
    }

    /**
     * @inheritDoc
     */
    public function setChatStatus(bool $status): self
    {
        $this->optionsService->set(self::CHAT_ACTIVE_KEY, $status);
        return $this;
    }
}
