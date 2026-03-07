<?php

namespace App\Services\Chat;

use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\InputPoolItem;

/**
 * Service to manage the input pool for chat presets.
 * The input pool allows accumulating multiple sources of input (e.g. user messages, webhooks)
 * into a single JSON payload that can be sent to the model in one go.
 */
class InputPoolService implements InputPoolServiceInterface
{
    public function __construct(
        protected OptionsServiceInterface $optionsService,
        protected InputPoolItem $poolItemModel,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(AiPreset $preset): bool
    {
        return $preset->getInputMode() === 'pool';
    }

    /**
     * @inheritDoc
     */
    public function add(int $presetId, string $sourceName, string $content): void
    {
        $this->poolItemModel->updateOrCreate(
            ['preset_id' => $presetId, 'source_name' => $sourceName],
            ['content' => $content]
        );
    }

    /**
     * @inheritDoc
     */
    public function flush(int $presetId): ?string
    {
        $items = $this->poolItemModel->forPreset($presetId)->orderBy('created_at')->get();

        if ($items->isEmpty()) {
            return null;
        }

        $json = $this->buildJson($items);
        $this->clear($presetId);

        return $json;
    }

    /**
     * @inheritDoc
     */
    public function clear(int $presetId): void
    {
        $this->poolItemModel->forPreset($presetId)->delete();
    }

    /**
     * @inheritDoc
     */
    public function getItems(int $presetId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->poolItemModel->forPreset($presetId)->orderBy('created_at')->get();
    }

    /**
     * Build JSON payload from pool items:
     * {
     *   "sources": [
     *     {"source": "message_from_user John:", "content": "..."},
     *     {"source": "webhook_weather",          "content": "..."}
     *   ]
     * }
     *
     * @param \Illuminate\Database\Eloquent\Collection $items
     * @return string
     */
    private function buildJson(\Illuminate\Database\Eloquent\Collection $items): string
    {
        $sources = $items->map(fn ($item) => [
            'source'  => $item->source_name,
            'content' => $item->content,
        ])->values()->all();

        return json_encode(['sources' => $sources], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
