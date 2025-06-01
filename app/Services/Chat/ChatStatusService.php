<?php

namespace App\Services\Chat;

use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use Exception;

class ChatStatusService implements ChatStatusServiceInterface
{
    public const CHAT_ACTIVE_KEY = 'chat_active';
    public const MODEL_AGENT_MODE_KEY = 'model_agent_mode';

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

    /**
     * @inheritDoc
     */
    public function getChatMode(): string
    {
        return $this->optionsService->get(self::MODEL_AGENT_MODE_KEY, 'looped');
    }

    /**
     * @inheritDoc
     */
    public function setChatMode(string $chatMode): self
    {
        $allowedModes = ['looped', 'single'];
        if (!in_array($chatMode, $allowedModes)) {
            throw new Exception("Chat mode not allowed: " . $chatMode);
        }
        $this->optionsService->set(self::MODEL_AGENT_MODE_KEY, $chatMode);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isLoopedMode(): bool
    {
        if ($this->getChatMode() === 'looped') {
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isSingleMode(): bool
    {
        if ($this->getChatMode() === 'single') {
            return true;
        }
        return false;
    }
}
