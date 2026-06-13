<?php

namespace App\Http\Requests\Admin\PluginData;

use Illuminate\Foundation\Http\FormRequest;

class StorePluginDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key'      => ['required', 'string', 'max:128'],
            'value'    => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
