<?php

// BaseJournalRequest.php

namespace App\Http\Requests\Admin\Journal;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function presetRules(): array
    {
        return ['preset_id' => 'required|exists:ai_presets,id'];
    }

    public function messages(): array
    {
        return [
            'preset_id.required' => 'Preset is required.',
            'preset_id.exists'   => 'Selected preset does not exist.',
        ];
    }
}
