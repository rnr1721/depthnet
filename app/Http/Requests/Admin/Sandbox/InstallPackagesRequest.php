<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Sandbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Install packages in sandbox request validation
 */
class InstallPackagesRequest extends FormRequest
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
            'packages' => 'required|array|min:1|max:20',
            'packages.*' => 'required|string|max:100',
            'language' => 'required|string|in:python,javascript,php,bash'
        ];
    }
}
