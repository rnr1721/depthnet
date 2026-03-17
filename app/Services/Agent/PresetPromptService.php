<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PresetPromptServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetPrompt;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Service for managing prompts within a preset.
 *
 * Business rules:
 *  - Every preset must always have at least one prompt.
 *  - Deleting the last prompt is forbidden.
 *  - Deleting the active prompt automatically promotes the first remaining one.
 *  - Codes are unique per preset (enforced at DB level too).
 */
class PresetPromptService implements PresetPromptServiceInterface
{
    public function __construct(
        protected DatabaseManager $db,
        protected LoggerInterface $logger
    ) {
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function getAll(AiPreset $preset): Collection
    {
        return $preset->prompts()->orderBy('created_at')->get();
    }

    /**
     * @inheritDoc
     */
    public function findById(AiPreset $preset, int $promptId): ?PresetPrompt
    {
        return $preset->prompts()->find($promptId);
    }

    /**
     * @inheritDoc
     */
    public function findByCode(AiPreset $preset, string $code): ?PresetPrompt
    {
        return $preset->prompts()->where('code', $code)->first();
    }

    /**
     * @inheritDoc
     */
    public function getActive(AiPreset $preset): ?PresetPrompt
    {
        if ($preset->active_prompt_id) {
            $prompt = $this->findById($preset, $preset->active_prompt_id);
            if ($prompt) {
                return $prompt;
            }
        }

        // Fallback: first prompt by creation order
        return $preset->prompts()->orderBy('created_at')->first();
    }

    // ─── Write ────────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function create(AiPreset $preset, array $data, bool $setAsActive = false): PresetPrompt
    {
        return $this->db->transaction(function () use ($preset, $data, $setAsActive) {
            $this->assertCodeUnique($preset, $data['code']);

            /** @var PresetPrompt $prompt */
            $prompt = $preset->prompts()->create([
                'code'        => $data['code'],
                'content'     => $data['content'],
                'description' => $data['description'] ?? null,
            ]);

            // First prompt in the preset is always set as active automatically
            $isFirst = $preset->prompts()->count() === 1;

            if ($setAsActive || $isFirst) {
                $preset->active_prompt_id = $prompt->getId();
                $preset->save();
            }

            $this->logger->info('PresetPromptService: Prompt created', [
                'preset_id'  => $preset->id,
                'prompt_id'  => $prompt->id,
                'code'       => $prompt->code,
                'set_active' => $setAsActive || $isFirst,
            ]);

            return $prompt;
        });
    }

    /**
     * @inheritDoc
     */
    public function update(AiPreset $preset, int $promptId, array $data): PresetPrompt
    {
        return $this->db->transaction(function () use ($preset, $promptId, $data) {
            $prompt = $this->findOrFail($preset, $promptId);

            if (isset($data['code']) && $data['code'] !== $prompt->code) {
                $this->assertCodeUnique($preset, $data['code']);
            }

            $prompt->update(array_filter([
                'code'        => $data['code']        ?? null,
                'content'     => $data['content']     ?? null,
                'description' => $data['description'] ?? null,
            ], fn ($v) => $v !== null));

            $this->logger->info('PresetPromptService: Prompt updated', [
                'preset_id' => $preset->id,
                'prompt_id' => $prompt->id,
            ]);

            return $prompt->fresh();
        });
    }

    /**
     * @inheritDoc
     */
    public function delete(AiPreset $preset, int $promptId): void
    {
        $this->db->transaction(function () use ($preset, $promptId) {
            $prompt = $this->findOrFail($preset, $promptId);

            $totalCount = $preset->prompts()->count();

            if ($totalCount <= 1) {
                throw new \RuntimeException(
                    "Cannot delete the last prompt of preset '{$preset->name}'. " .
                    "A preset must always have at least one prompt."
                );
            }

            $isActive = $preset->active_prompt_id === $prompt->getId();

            $prompt->delete();

            if ($isActive) {
                $newActive = $preset->prompts()->orderBy('created_at')->first();
                $preset->active_prompt_id = $newActive?->getId();
                $preset->save();

                $this->logger->info('PresetPromptService: Active prompt deleted, promoted first available', [
                    'preset_id'        => $preset->id,
                    'deleted_id'       => $promptId,
                    'new_active_id'    => $newActive?->getId(),
                    'new_active_code'  => $newActive?->code,
                ]);
            } else {
                $this->logger->info('PresetPromptService: Prompt deleted', [
                    'preset_id' => $preset->id,
                    'prompt_id' => $promptId,
                ]);
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function setActive(AiPreset $preset, int $promptId): void
    {
        $this->db->transaction(function () use ($preset, $promptId) {
            $prompt = $this->findOrFail($preset, $promptId);

            $preset->active_prompt_id = $prompt->getId();
            $preset->save();

            $this->logger->info('PresetPromptService: Active prompt changed', [
                'preset_id' => $preset->id,
                'prompt_id' => $prompt->id,
                'code'      => $prompt->code,
            ]);
        });
    }

    /**
     * @inheritDoc
     */
    public function setActiveByCode(AiPreset $preset, string $code): PresetPrompt
    {
        return $this->db->transaction(function () use ($preset, $code) {
            $prompt = $this->findByCode($preset, $code);

            if (!$prompt) {
                throw new \RuntimeException(
                    "Prompt code '{$code}' not found for preset '{$preset->name}'. " .
                    "Available codes: " . implode(', ', $preset->getAvailablePromptCodes())
                );
            }

            $preset->active_prompt_id = $prompt->getId();
            $preset->save();

            $this->logger->info('PresetPromptService: Prompt switched by code', [
                'preset_id' => $preset->id,
                'prompt_id' => $prompt->id,
                'code'      => $code,
            ]);

            return $prompt;
        });
    }

    /**
     * @inheritDoc
     */
    public function duplicate(AiPreset $preset, int $promptId): PresetPrompt
    {
        $source = $this->findOrFail($preset, $promptId);

        $newCode = $this->generateUniqueCode($preset, $source->code . '_copy');

        return $this->create($preset, [
            'code'        => $newCode,
            'content'     => $source->getContent(),
            'description' => $source->getDescription()
                ? $source->getDescription() . ' (copy)'
                : null,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Find prompt or throw if not found / doesn't belong to preset.
     *
     * @throws \RuntimeException
     */
    protected function findOrFail(AiPreset $preset, int $promptId): PresetPrompt
    {
        $prompt = $this->findById($preset, $promptId);

        if (!$prompt) {
            throw new \RuntimeException(
                "Prompt #{$promptId} not found in preset '{$preset->name}'"
            );
        }

        return $prompt;
    }

    /**
     * Assert that a code is unique within the preset.
     *
     * @throws \RuntimeException
     */
    protected function assertCodeUnique(AiPreset $preset, string $code): void
    {
        $exists = $preset->prompts()->where('code', $code)->exists();

        if ($exists) {
            throw new \RuntimeException(
                "A prompt with code '{$code}' already exists in preset '{$preset->name}'"
            );
        }
    }

    /**
     * Generate a unique code within a preset by appending _1, _2, … as needed.
     *
     * @param AiPreset $preset
     * @param string $base
     * @return string
     */
    protected function generateUniqueCode(AiPreset $preset, string $base): string
    {
        $code = $base;
        $i = 1;

        while ($preset->prompts()->where('code', $code)->exists()) {
            $code = "{$base}_{$i}";
            $i++;
        }

        return $code;
    }
}
