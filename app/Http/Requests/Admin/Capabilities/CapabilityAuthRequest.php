<?php

namespace App\Http\Requests\Admin\Capabilities;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One step of an interactive capability login.
 *
 * The fields inside `input` are defined by the provider's AuthStep, so they are
 * not validated here — the provider validates them and answers with the same
 * step carrying an error.
 */
class CapabilityAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'step'    => ['required', 'string', 'max:50'],
            'input'   => ['nullable', 'array'],
            'input.*' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
