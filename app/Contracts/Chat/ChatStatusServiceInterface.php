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

}
