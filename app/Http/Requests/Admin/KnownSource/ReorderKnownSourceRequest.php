<?php

namespace App\Http\Requests\Admin\KnownSource;

use Illuminate\Foundation\Http\FormRequest;

class ReorderKnownSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'     => ['required', 'integer', 'exists:ai_presets,id'],
            'ordered_ids'   => ['required', 'array'],
            'ordered_ids.*' => ['integer'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getOrderedIds(): array
    {
        return $this->input('ordered_ids');
    }
}
