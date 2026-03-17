<?php

namespace App\Http\Requests\Admin\Skill;

use Illuminate\Foundation\Http\FormRequest;

class ShowSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => 'nullable|integer',
        ];
    }

    public function getPresetId(): ?int
    {
        $id = $this->validated('preset_id');
        return $id ? (int) $id : null;
    }
}
