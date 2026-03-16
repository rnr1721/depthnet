<?php

namespace App\Contracts\Chat;

interface ChatStatusServiceInterface
{
    /**
     * Get global chat status (legacy, checks if ANY preset is active)
     *
     * @return boolean
     */
    public function getChatStatus(): bool;

    /**
     * Set global chat status (legacy)
     *
     * @param boolean $status
     * @return self
     */
    public function setChatStatus(bool $status): self;

    /**
     * Get active status for a specific preset
     *
     * @param int $presetId
     * @return bool
     */
    public function getPresetStatus(int $presetId): bool;

    /**
     * Set active status for a specific preset
     *
     * @param int $presetId
     * @param bool $status
     * @return self
     */
    public function setPresetStatus(int $presetId, bool $status): self;

    /**
     * Get IDs of all presets currently marked as active
     *
     * @return int[]
     */
    public function getActivePresetIds(): array;
}
