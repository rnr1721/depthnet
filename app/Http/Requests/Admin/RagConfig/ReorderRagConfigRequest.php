<?php

namespace App\Http\Requests\Admin\RagConfig;

use Illuminate\Foundation\Http\FormRequest;

class ReorderRagConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ];
    }
}
