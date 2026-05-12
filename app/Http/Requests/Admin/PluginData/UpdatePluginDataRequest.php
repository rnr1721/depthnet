<?php

namespace App\Http\Requests\Admin\PluginData;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePluginDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key'      => ['sometimes', 'string', 'max:128'],
            'value'    => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
