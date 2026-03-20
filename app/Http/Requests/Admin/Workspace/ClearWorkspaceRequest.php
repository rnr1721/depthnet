<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class ClearWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
}
