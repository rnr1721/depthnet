<?php

namespace App\Http\Requests\Admin\Person;

use Illuminate\Foundation\Http\FormRequest;

class AddFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'   => ['required', 'integer'],
            'person_name' => ['required', 'string', 'max:255'],
            'content'     => ['required', 'string'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getPersonName(): string
    {
        return $this->input('person_name');
    }
    public function getPersonContent(): string
    {
        return $this->input('content');
    }
}
