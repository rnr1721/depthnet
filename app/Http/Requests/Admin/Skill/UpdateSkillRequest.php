<?php

namespace App\Http\Requests\Admin\Skill;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSkillRequest extends FormRequest
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
            'title'        => 'nullable|string|max:255',
            // agentskills.io spec: description ≤ 1024 chars (column is TEXT now).
            'description'  => 'nullable|string|max:1024',
            'tools'        => 'nullable|array',
            'tools.*'      => 'string|max:100',
            'is_experience' => 'nullable|boolean',
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

    /** Only the fields actually present, for a partial update. */
    public function getFields(): array
    {
        $fields = [];
        foreach (['title', 'description', 'tools', 'is_experience'] as $key) {
            if ($this->has($key)) {
                $fields[$key] = $this->validated($key);
            }
        }
        return $fields;
    }
}
