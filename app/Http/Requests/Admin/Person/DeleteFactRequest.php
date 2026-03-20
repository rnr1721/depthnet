<?php

namespace App\Http\Requests\Admin\Person;

use Illuminate\Foundation\Http\FormRequest;

class DeleteFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'   => ['required', 'integer'],
            'person_name' => ['required', 'string'],
            'fact_number' => ['required', 'integer', 'min:1'],
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
    public function getFactNumber(): int
    {
        return (int) $this->input('fact_number');
    }
}
