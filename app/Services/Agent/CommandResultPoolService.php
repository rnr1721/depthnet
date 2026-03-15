<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandResultPoolInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Models\PresetCommandResult;

class CommandResultPoolService implements CommandResultPoolInterface
{
    private const RESULT_DIVIDER = "\n-----\n";
    private const DEFAULT_CONTEXT_LIMIT = 10;

    public function __construct(
        protected PresetCommandResult $model,
        protected Message $messageModel
    ) {
    }

    /**
     * @inheritDoc
     */
    public function push(AiPreset $preset, Message $message, string $results): void
    {
        $this->model->create([
            'preset_id'  => $preset->getId(),
            'message_id' => $message->id,
            'results'    => $results,
        ]);

        $this->prune($preset);
    }

    /**
     * @inheritDoc
     */
    public function getAll(AiPreset $preset): array
    {
        return $this->model
            ->where('preset_id', $preset->getId())
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->pluck('results')
            ->all();
    }

    /**
     * @inheritDoc
     */
    public function getFormatted(AiPreset $preset): string
    {
        $entries = $this->getAll($preset);

        if (empty($entries)) {
            return '';
        }

        return implode(self::RESULT_DIVIDER, $entries);
    }

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset): void
    {
        $this->model
            ->where('preset_id', $preset->getId())
            ->delete();
    }

    /**
     * Remove pool entries whose linked messages have fallen outside
     * the current context window.
     *
     * Logic:
     *   1. Collect IDs of the last max_context_limit messages for this preset.
     *   2. Delete pool entries whose message_id is NOT in that set.
     *
     * No manual counting needed — if the message was deleted by the user,
     * the pool entry is already gone via cascadeOnDelete.
     * This prune only handles the "scrolled out of context window" case.
     *
     * @param AiPreset $preset
     * @return void
     */
    private function prune(AiPreset $preset): void
    {
        $contextLimit = $preset->getMaxContextLimit() ?: self::DEFAULT_CONTEXT_LIMIT;

        $contextMessageIds = $this->messageModel
            ->forPreset($preset->getId())
            ->where('role', '!=', 'system')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($contextLimit)
            ->pluck('id');

        $this->model
            ->where('preset_id', $preset->getId())
            ->whereNotIn('message_id', $contextMessageIds)
            ->delete();
    }
}
