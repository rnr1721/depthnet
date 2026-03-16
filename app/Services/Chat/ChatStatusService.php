<?php

namespace App\Services\Chat;

use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;

class ChatStatusService implements ChatStatusServiceInterface
{
    /**
     * Legacy key (kept for backward compatibility during migration)
     */
    public const CHAT_ACTIVE_KEY = 'chat_active';

    /**
     * Key prefix for per-preset active status: preset_active_{id}
     */
    public const PRESET_ACTIVE_PREFIX = 'preset_active_';

    /**
     * Key storing the list of active preset IDs as a JSON array
     */
    public const ACTIVE_PRESETS_KEY = 'active_preset_ids';

    public function __construct(
        protected OptionsServiceInterface $optionsService
    ) {
    }

    /**
     * @inheritDoc
     *
     * Returns true if at least one preset is active.
     */
    public function getChatStatus(): bool
    {
        return count($this->getActivePresetIds()) > 0;
    }

    /**
     * @inheritDoc
     *
     * Legacy setter — kept so existing call-sites don't break during migration.
     * Does NOT enable/disable any specific preset; only clears all when false.
     */
    public function setChatStatus(bool $status): self
    {
        if (!$status) {
            // Deactivate every preset that is currently active
            foreach ($this->getActivePresetIds() as $presetId) {
                $this->setPresetStatus($presetId, false);
            }
        }
        // Setting true globally without a preset ID is a no-op:
        // callers should use setPresetStatus($id, true) instead.
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPresetStatus(int $presetId): bool
    {
        return $this->optionsService->get(self::PRESET_ACTIVE_PREFIX . $presetId, false) === true;
    }

    /**
     * @inheritDoc
     */
    public function setPresetStatus(int $presetId, bool $status): self
    {
        $this->optionsService->set(self::PRESET_ACTIVE_PREFIX . $presetId, $status);
        $this->syncActivePresetIds($presetId, $status);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getActivePresetIds(): array
    {
        $raw = $this->optionsService->get(self::ACTIVE_PRESETS_KEY, []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }
        return array_values(array_unique(array_map('intval', (array) $raw)));
    }

    /**
     * Keep the active-preset-IDs index in sync whenever a preset status changes.
     *
     * @param integer $presetId
     * @param boolean $active
     * @return void
     */
    private function syncActivePresetIds(int $presetId, bool $active): void
    {
        $ids = $this->getActivePresetIds();

        if ($active) {
            if (!in_array($presetId, $ids, true)) {
                $ids[] = $presetId;
            }
        } else {
            $ids = array_values(array_filter($ids, fn ($id) => $id !== $presetId));
        }

        $this->optionsService->set(self::ACTIVE_PRESETS_KEY, $ids);
    }
}
