<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sandbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Execute command in sandbox request validation
 */
class ExecuteCommandRequest extends FormRequest
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
            'command' => 'required|string|max:1000',
            'user' => 'nullable|string|in:sandbox-user,root',
            'timeout' => 'nullable|integer|min:1|max:300'
        ];
    }
}
