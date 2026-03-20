<?php

namespace App\Http\Requests\Admin\KnownSource;

use Illuminate\Foundation\Http\FormRequest;

class StorePoolItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'   => ['required', 'integer', 'exists:ai_presets,id'],
            'source_name' => ['required', 'string', 'max:100'],
            'content'     => ['required', 'string', 'max:2000'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getSourceName(): string
    {
        return $this->input('source_name');
    }
    public function getPoolItemContent(): string
    {
        return $this->input('content');
    }
}
