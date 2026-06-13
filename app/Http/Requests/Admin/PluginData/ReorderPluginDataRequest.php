<?php

namespace App\Http\Requests\Admin\PluginData;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPluginDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ];
    }
}
