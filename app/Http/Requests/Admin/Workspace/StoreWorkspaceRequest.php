<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer'],
            'key'       => ['required', 'string', 'max:255'],
            'value'     => ['required', 'string'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }

    public function getKey(): string
    {
        return $this->input('key');
    }

    public function getValue(): string
    {
        return $this->input('value');
    }
}
