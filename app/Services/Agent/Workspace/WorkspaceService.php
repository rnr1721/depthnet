<?php

namespace App\Services\Agent\Workspace;

use App\Contracts\Agent\Workspace\WorkspaceServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetWorkspace;

class WorkspaceService implements WorkspaceServiceInterface
{
    public function __construct(
        protected PresetWorkspace $model
    ) {
    }

    /**
     * @inheritDoc
     */
    public function set(AiPreset $preset, string $key, string $value): bool
    {
        $this->model->updateOrCreate(
            ['preset_id' => $preset->getId(), 'key' => $key],
            ['value' => $value]
        );

        return true;
    }

    /**
     * @inheritDoc
     */
    public function append(AiPreset $preset, string $key, string $value): bool
    {
        $existing = $this->get($preset, $key);
        $newValue  = $existing !== null ? $existing . "\n" . $value : $value;

        return $this->set($preset, $key, $newValue);
    }

    /**
     * @inheritDoc
     */
    public function get(AiPreset $preset, string $key): ?string
    {
        $row = $this->model
            ->where('preset_id', $preset->getId())
            ->where('key', $key)
            ->first();

        return $row?->value;
    }

    /**
     * @inheritDoc
     */
    public function delete(AiPreset $preset, string $key): bool
    {
        $deleted = $this->model
            ->where('preset_id', $preset->getId())
            ->where('key', $key)
            ->delete();

        return $deleted > 0;
    }

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset): bool
    {
        $this->model
            ->where('preset_id', $preset->getId())
            ->delete();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function all(AiPreset $preset): array
    {
        return $this->model
            ->where('preset_id', $preset->getId())
            ->orderBy('key')
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * @inheritDoc
     */
    public function getFormatted(AiPreset $preset): string
    {
        $entries = $this->all($preset);
        if (empty($entries)) {
            return '';
        }

        $lines = ['[WORKSPACE]', ''];

        foreach ($entries as $key => $value) {
            $lines[] = "--- {$key} ---";
            $lines[] = $value;
            $lines[] = '';
        }

        $lines[] = '[/WORKSPACE]';

        return implode("\n", $lines);
    }
}
