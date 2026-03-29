<?php

namespace App\Http\Requests\Admin\Person;

use Illuminate\Foundation\Http\FormRequest;

class RemoveAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer'],
            'fact_id'   => ['required', 'integer', 'min:1'],
            'alias'     => ['required', 'string', 'max:255'],
        ];
    }

    public function getPresetId(): int { return (int) $this->input('preset_id'); }
    public function getFactId(): int   { return (int) $this->input('fact_id'); }
    public function getAlias(): string { return $this->input('alias'); }
}
