<?php

namespace App\Http\Requests\Admin\Telegram;

use Illuminate\Foundation\Http\FormRequest;

class AuthInitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'api_id'   => ['required', 'numeric'],
            'api_hash' => ['required', 'string', 'min:10'],
        ];
    }
}
