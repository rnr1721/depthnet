<?php

namespace App\Http\Requests\Admin\Person;

use Illuminate\Foundation\Http\FormRequest;

class ForgetPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'  => ['required', 'integer'],
            'name_or_id' => ['required', 'string', 'max:255'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }

    public function getNameOrId(): string
    {
        return $this->input('name_or_id');
    }
}
