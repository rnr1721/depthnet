<?php

namespace App\Services\Chat;

use App\Contracts\Chat\InputPoolServiceInterface;
use App\Contracts\Settings\OptionsServiceInterface;
use App\Models\AiPreset;
use App\Models\InputPoolItem;
use App\Models\Message;
use App\Models\PresetKnownSource;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service to manage the input pool for chat presets.
 *
 * The input pool accumulates messages from multiple sources (user input, webhooks,
 * sensors, inner voice) into a single JSON payload sent to the model as a user message.
 *
 * ## Two types of sources
 *
 * **Regular sources** — contribute to the JSON payload that becomes the user message.
 * Cleared after each cycle by AgentActionsHandler.
 *
 * **Known sources** — defined in `preset_known_sources` table. Their pool items are
 * excluded from the JSON payload and instead exposed via getKnownSourcesBlock(), which
 * context builders (CycleContextBuilder, SingleContextBuilder) use to register the
 * [[known_sources]] shortcode in the system prompt. This lets the model treat these
 * inputs as part of its own context — sensor state, body projection, ambient signals —
 * rather than as incoming external messages.
 *
 * ## Shortcode registration
 *
 * This service does NOT register shortcodes. That is the responsibility of the context
 * builders, where ShortcodeManagerServiceInterface is already available alongside
 * other shortcode registrations (rag_context, inner_voice, etc.).
 */
class InputPoolService implements InputPoolServiceInterface
{
    public function __construct(
        protected OptionsServiceInterface $optionsService,
        protected InputPoolItem $poolItemModel,
        protected PresetKnownSource $knownSourceModel,
        protected Message $messageModel,
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
    public function getAllAsJSON(AiPreset $preset): ?string
    {
        $items = $this->poolItemModel->forPreset($preset->getId())->orderBy('created_at')->get();
        if ($items->isEmpty()) {
            return null;
        }

        $knownNames = $this->knownSourceModel
            ->forPreset($preset->getId())
            ->pluck('source_name')
            ->toArray();

        [, $regular] = $items->partition(
            fn ($item) => in_array($item->source_name, $knownNames)
        );

        return $this->buildJson($regular, $preset);
    }

    /**
     * @inheritDoc
     */
    public function flush(AiPreset $preset): ?string
    {
        $result = $this->getAllAsJSON($preset);
        $this->clear($preset->getId());
        return $result;
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
    public function getItems(int $presetId): Collection
    {
        return $this->poolItemModel->forPreset($presetId)->orderBy('created_at')->get();
    }

    /**
     * @inheritDoc
     */
    public function removeItem(int $presetId, string $sourceName): void
    {
        $this->poolItemModel
            ->forPreset($presetId)
            ->where('source_name', $sourceName)
            ->delete();
    }

    // -------------------------------------------------------------------------
    // Known sources
    // -------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getKnownSourcesBlock(int $presetId): ?string
    {
        $allDefined = $this->knownSourceModel->forPreset($presetId)->get();
        if ($allDefined->isEmpty()) {
            return null;
        }

        $arrived    = $this->poolItemModel->forPreset($presetId)->get();
        $arrivedMap = $arrived->keyBy('source_name');

        $lines = $allDefined->map(function ($defined) use ($arrivedMap) {
            if ($arrivedMap->has($defined->source_name)) {
                $item = $arrivedMap->get($defined->source_name);
                return sprintf(
                    '[%s] %s (%s)',
                    $defined->source_name,
                    $item->content,
                    $item->created_at->toIso8601String()
                );
            }
            if (!is_null($defined->default_value)) {
                return sprintf(
                    '[%s] %s (default)',
                    $defined->source_name,
                    $defined->default_value
                );
            }
            return null;
        })->filter()->implode("\n");

        return $lines ?: null;
    }

    /**
     * @inheritDoc
     */
    public function getKnownSources(int $presetId): Collection
    {
        return $this->knownSourceModel->forPreset($presetId)->get();
    }

    /**
     * @inheritDoc
     */
    public function addKnownSource(
        int $presetId,
        string $sourceName,
        string $label,
        ?string $description = null,
        ?string $defaultValue = null,
    ): PresetKnownSource {
        return $this->knownSourceModel->updateOrCreate(
            ['preset_id' => $presetId, 'source_name' => $sourceName],
            ['label' => $label, 'description' => $description, 'default_value' => $defaultValue]
        );
    }

    /**
     * @inheritDoc
     */
    public function removeKnownSource(int $presetId, string $sourceName): void
    {
        $this->knownSourceModel
            ->forPreset($presetId)
            ->where('source_name', $sourceName)
            ->delete();
    }

    /**
     * @inheritDoc
     */
    public function reorderKnownSources(int $presetId, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            $this->knownSourceModel
                ->where('id', $id)
                ->where('preset_id', $presetId)
                ->update(['sort_order' => $position]);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Build JSON payload from pool items:
     * {
     *   "sources": [
     *     {"source": "message_from_user John:", "content": "..."},
     *     {"source": "webhook_weather",          "content": "..."}
     *   ]
     * }
     */
    private function buildJson(Collection $items, AiPreset $preset): string
    {
        $reference = null;

        if ($preset->getRagRelativeDates()) {
            $lastMessage = $this->messageModel->forPreset($preset->getId())
                ->whereIn('role', ['thinking', 'command'])
                ->latest()
                ->value('created_at');

            $reference = $lastMessage ? \Carbon\Carbon::parse($lastMessage) : now();
        }

        $sources = $items->map(function ($item) use ($reference) {
            $entry = [
                'source'    => $item->source_name,
                'content'   => $item->content,
                'timestamp' => $item->created_at->toIso8601String(),
                'ago'       => $reference ? $item->created_at->diffForHumans($reference, true) : null,
            ];
            return $entry;
        })->values()->all();

        return json_encode(['sources' => $sources], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
