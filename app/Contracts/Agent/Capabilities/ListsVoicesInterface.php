<?php

namespace App\Contracts\Agent\Capabilities;

/**
 * Optional interface for TTS providers that can enumerate available voices.
 *
 * Same idea as ListsModelsInterface, one level deeper: the voice list usually
 * depends on the selected model (Fish Audio and MiniMax ship different voice
 * sets), so listVoices() takes the model as an argument.
 *
 * The browser provider does NOT implement this — its voices live on the user's
 * machine and are enumerated client-side via speechSynthesis.getVoices().
 */
interface ListsVoicesInterface
{
    /**
     * @param  string|null $model Model to scope the list to. Null → the
     *                            provider's configured model.
     * @return array<int, array<string, string>> Each item: id, title, and
     *                            optionally language, gender, description.
     */
    public function listVoices(?string $model = null): array;
}
