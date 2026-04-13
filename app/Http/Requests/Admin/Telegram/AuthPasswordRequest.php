<?php

namespace App\Http\Requests\Admin\Telegram;

use Illuminate\Foundation\Http\FormRequest;

class AuthPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:1'],
        ];
    }
}
