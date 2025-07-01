<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sandbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Execute code in sandbox request validation
 */
class ExecuteCodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return [
            'code' => 'required|string|max:10000',
            'language' => 'required|string|in:python,javascript,php,bash',
            'timeout' => 'nullable|integer|min:1|max:300',
            'filename' => 'nullable|string|max:100'
        ];
    }
}
