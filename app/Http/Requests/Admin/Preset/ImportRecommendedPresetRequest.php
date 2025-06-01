<?php

namespace App\Http\Requests\Admin\Preset;

use Illuminate\Foundation\Http\FormRequest;

class ImportRecommendedPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'engine_name' => ['required', 'string'],
            'preset_index' => ['required', 'integer', 'min:0']
        ];
    }
}
