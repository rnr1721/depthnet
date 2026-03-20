<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer'],
            'value'     => ['required', 'string'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }

    public function getValue(): string
    {
        return $this->input('value');
    }
}
