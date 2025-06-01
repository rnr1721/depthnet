<?php

namespace App\Contracts\Chat;

interface ChatStatusServiceInterface
{
    /**
     * Get chat ststus, is active?
     *
     * @return boolean
     */
    public function getChatStatus(): bool;

    /**
     * Set chat active status
     *
     * @param boolean $status Chat status
     * @return self
     */
    public function setChatStatus(bool $status): self;

    /**
     * Get chat mode
     *
     * @return string can return single or looped
     */
    public function getChatMode(): string;

    /**
     * Set chat mode
     *
     * @param string $chatMode (single or looped)
     * @return self
     */
    public function setChatMode(string $chatMode): self;

    /**
     * Returns true if chat mode === looped
     *
     * @return boolean
     */
    public function isLoopedMode(): bool;

    /**
     * Returns true if chat mode === looped
     *
     * @return boolean
     */
    public function isSingleMode(): bool;
}
