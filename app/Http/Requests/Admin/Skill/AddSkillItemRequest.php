<?php

namespace App\Http\Requests\Admin\Skill;

use Illuminate\Foundation\Http\FormRequest;

class AddSkillItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'    => 'required|integer',
            'skill_number' => 'required|integer|min:1',
            'content'      => 'required|string',
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }

    public function getSkillNumber(): int
    {
        return (int) $this->validated('skill_number');
    }

    public function getContent(bool $asResource = false)
    {
        return $this->validated('content');
    }
}
