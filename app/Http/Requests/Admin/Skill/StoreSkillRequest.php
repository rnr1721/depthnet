<?php

namespace App\Http\Requests\Admin\Skill;

use Illuminate\Foundation\Http\FormRequest;

class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'   => 'required|integer',
            'title'       => 'required|string|max:255',
            // agentskills.io spec: description ≤ 1024 chars (column is TEXT now).
            'description' => 'nullable|string|max:1024',
            'first_item'  => 'nullable|string',
            'tools'       => 'nullable|array',
            'tools.*'     => 'string|max:100',
            'is_experience' => 'nullable|boolean',
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }

    public function getTitle(): string
    {
        return $this->validated('title');
    }

    public function getDescription(): ?string
    {
        $desc = $this->validated('description');
        return $desc ?: null;
    }

    public function getFirstItem(): ?string
    {
        $item = $this->validated('first_item');
        return $item ?: null;
    }

    public function getTools(): ?array
    {
        return $this->validated('tools');
    }

    /** Skills created in the UI are classic unless explicitly marked. */
    public function getIsExperience(): bool
    {
        return (bool) $this->validated('is_experience', false);
    }
}
