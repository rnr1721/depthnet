<?php

namespace App\Http\Requests\Admin\Skill;

use Illuminate\Foundation\Http\FormRequest;

class DestroySkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => 'required|integer',
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }
}
