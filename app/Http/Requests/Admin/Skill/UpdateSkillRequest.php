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
            'description'  => 'nullable|string|max:500',
            'tools'        => 'nullable|array',
            'tools.*'      => 'string|max:100',
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
        foreach (['title', 'description', 'tools'] as $key) {
            if ($this->has($key)) {
                $fields[$key] = $this->validated($key);
            }
        }
        return $fields;
    }
}
