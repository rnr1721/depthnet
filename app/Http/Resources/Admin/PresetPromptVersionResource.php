<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PresetPromptVersion
 *
 * Serialises a single prompt version for the admin UI.
 *
 * `content` is included in full — versions are prompt-sized (<=20k) and the UI
 * needs it for diff/preview.
 *
 * `is_current` (whether this version matches the prompt's live head content)
 * is NOT computed here — a version doesn't know the head. The controller sets
 * it via a lightweight post-map when it has the current content. Kept out of
 * the Resource to avoid leaking head-state knowledge into a per-row serialiser.
 */
class PresetPromptVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'prompt_id'      => $this->prompt_id,
            'version'        => $this->version,
            'content'        => $this->content,
            'edit_summary'   => $this->edit_summary,
            'edited_by'      => $this->edited_by,
            'editor_user_id' => $this->editor_user_id,
            'created_at'     => $this->created_at?->toISOString(),
        ];
    }
}
