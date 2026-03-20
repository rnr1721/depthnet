<?php

namespace App\Http\Requests\Admin\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class IndexWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['nullable', 'integer'],
        ];
    }

    public function getPresetId(): ?int
    {
        $value = $this->query('preset_id');
        return $value !== null ? (int) $value : null;
    }
}
