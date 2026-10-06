<?php

namespace App\Services\Agent\Prompt;

use App\Contracts\Agent\Prompt\PresetPromptServiceInterface;
use App\Models\AiPreset;
use App\Models\PresetPrompt;
use App\Models\PresetPromptVersion;
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
 *
 * Versioning:
 *  - Every content-changing write appends an immutable snapshot to
 *    preset_prompt_versions (see PresetPromptVersion).
 *  - v1 is written at prompt creation (the original state).
 *  - update() snapshots ONLY when content actually changes — metadata-only
 *    edits (code/description) don't create versions.
 *  - revertToVersion() never mutates history; it appends a NEW version whose
 *    content equals the reverted-to version.
 *  - Actor (edited_by / editor_user_id) is passed from the caller: the plugin
 *    passes 'agent', the controller passes 'human' + the user id, seeding/
 *    automation defaults to 'system'.
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

    // ─── Version reads ─────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function getHistory(AiPreset $preset, int $promptId): Collection
    {
        $prompt = $this->findOrFail($preset, $promptId);

        // Newest first (relation is already ordered desc, but be explicit).
        return $prompt->versions()->orderByDesc('version')->get();
    }

    /**
     * @inheritDoc
     */
    public function getVersion(AiPreset $preset, int $promptId, int $version): ?PresetPromptVersion
    {
        $prompt = $this->findOrFail($preset, $promptId);

        return $prompt->versions()->where('version', $version)->first();
    }

    // ─── Write ────────────────────────────────────────────────────────────────

    /**
     * @inheritDoc
     */
    public function create(
        AiPreset $preset,
        array $data,
        bool $setAsActive = false,
        string $editedBy = PresetPromptVersion::BY_SYSTEM,
        ?int $editorUserId = null
    ): PresetPrompt {
        return $this->db->transaction(function () use ($preset, $data, $setAsActive, $editedBy, $editorUserId) {
            $this->assertCodeUnique($preset, $data['code']);

            /** @var PresetPrompt $prompt */
            $prompt = $preset->prompts()->create([
                'code'         => $data['code'],
                'context_mode' => $data['context_mode'] ?? PresetPrompt::MODE_NONE,
                'content'      => $data['content'],
                'description'  => $data['description'] ?? null,
            ]);

            // First prompt in the preset is always set as active automatically
            $isFirst = $preset->prompts()->count() === 1;

            if ($setAsActive || $isFirst) {
                $preset->active_prompt_id = $prompt->getId();
                $preset->save();
            }

            // v1 — the prompt's original state. Enables revert back to birth.
            $this->snapshot(
                $prompt,
                $prompt->getContent(),
                'Initial version',
                $editedBy,
                $editorUserId
            );

            $this->logger->info('PresetPromptService: Prompt created', [
                'preset_id'  => $preset->id,
                'prompt_id'  => $prompt->id,
                'code'       => $prompt->code,
                'set_active' => $setAsActive || $isFirst,
                'edited_by'  => $editedBy,
            ]);

            return $prompt;
        });
    }

    /**
     * @inheritDoc
     */
    public function update(
        AiPreset $preset,
        int $promptId,
        array $data,
        string $editedBy = PresetPromptVersion::BY_SYSTEM,
        ?int $editorUserId = null
    ): PresetPrompt {
        return $this->db->transaction(function () use ($preset, $promptId, $data, $editedBy, $editorUserId) {
            $prompt = $this->findOrFail($preset, $promptId);

            if (isset($data['code']) && $data['code'] !== $prompt->code) {
                $this->assertCodeUnique($preset, $data['code']);
            }

            // Capture content before the update to detect a real change.
            $contentBefore = $prompt->getContent();
            $contentGiven  = array_key_exists('content', $data) && $data['content'] !== null;
            $contentAfter  = $contentGiven ? $data['content'] : $contentBefore;

            $prompt->update(array_filter([
                'code'        => $data['code']        ?? null,
                'content'     => $data['content']     ?? null,
                'description' => $data['description'] ?? null,
            ], fn ($v) => $v !== null));

            // Snapshot ONLY when content actually changed. Metadata-only edits
            // (code/description) don't pollute history with empty versions.
            if ($contentGiven && $contentAfter !== $contentBefore) {
                $this->snapshot(
                    $prompt,
                    $contentAfter,
                    $data['edit_summary'] ?? null,
                    $editedBy,
                    $editorUserId
                );
            }

            $this->logger->info('PresetPromptService: Prompt updated', [
                'preset_id'       => $preset->id,
                'prompt_id'       => $prompt->id,
                'content_changed' => $contentGiven && $contentAfter !== $contentBefore,
                'edited_by'       => $editedBy,
            ]);

            return $prompt->fresh();
        });
    }

    /**
     * @inheritDoc
     */
    public function revertToVersion(
        AiPreset $preset,
        int $promptId,
        int $targetVersion,
        string $editedBy = PresetPromptVersion::BY_SYSTEM,
        ?int $editorUserId = null
    ): PresetPrompt {
        return $this->db->transaction(function () use ($preset, $promptId, $targetVersion, $editedBy, $editorUserId) {
            $prompt = $this->findOrFail($preset, $promptId);

            $target = $prompt->versions()->where('version', $targetVersion)->first();

            if (!$target) {
                throw new \RuntimeException(
                    "Version {$targetVersion} not found for prompt #{$promptId}."
                );
            }

            // No-op guard: reverting to content identical to current is pointless.
            if ($prompt->getContent() === $target->getContent()) {
                throw new \RuntimeException(
                    "Prompt is already at the content of version {$targetVersion}."
                );
            }

            // Restore content onto the head…
            $prompt->update(['content' => $target->getContent()]);

            // …and append a NEW version. History stays monotonic and the revert
            // itself is visible as its own version — no history rewriting.
            $this->snapshot(
                $prompt,
                $target->getContent(),
                "Reverted to v{$targetVersion}",
                $editedBy,
                $editorUserId
            );

            $this->logger->info('PresetPromptService: Prompt reverted', [
                'preset_id'      => $preset->id,
                'prompt_id'      => $prompt->id,
                'target_version' => $targetVersion,
                'edited_by'      => $editedBy,
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

            // Versions cascade-delete with the prompt at the DB level.
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

        // A duplicate is a brand-new prompt; its history starts fresh at v1
        // with the copied content. create() handles the v1 snapshot.
        return $this->create($preset, [
            'code'         => $newCode,
            'context_mode' => PresetPrompt::MODE_NONE,
            'content'      => $source->getContent(),
            'description'  => $source->getDescription()
                ? $source->getDescription() . ' (copy)'
                : null,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Append an immutable version snapshot for a prompt.
     *
     * Single point of version writes: computes the next monotonic version
     * number (MAX+1) under a row lock to stay race-safe, then inserts.
     * Assumes it runs inside an outer transaction (all callers wrap it).
     */
    protected function snapshot(
        PresetPrompt $prompt,
        string $content,
        ?string $editSummary,
        string $editedBy,
        ?int $editorUserId
    ): PresetPromptVersion {
        // Lock the prompt row so concurrent edits can't compute the same
        // MAX(version)+1. Practically impossible in this app's usage, but the
        // guard is cheap and removes the question entirely.
        $prompt->newQuery()
            ->whereKey($prompt->getKey())
            ->lockForUpdate()
            ->first();

        $next = (int) $prompt->versions()->max('version') + 1;

        return $prompt->versions()->create([
            'version'        => $next,
            'content'        => $content,
            'edit_summary'   => $editSummary,
            'edited_by'      => $editedBy,
            'editor_user_id' => $editorUserId,
        ]);
    }

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
