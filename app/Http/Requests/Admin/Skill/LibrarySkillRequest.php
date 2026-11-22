<?php

namespace App\Http\Requests\Admin\Skill;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Library actions on a preset: list, attach (name), refresh (skill_number or all).
 */
class LibrarySkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'    => 'required|integer',
            // Library folder name: same rule as SkillMdParser::isValidName.
            'name'         => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'skill_number' => 'nullable|integer|min:1',
            'force'        => 'nullable|boolean',
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->validated('preset_id');
    }

    public function getName(): ?string
    {
        $name = $this->validated('name');
        return is_string($name) && $name !== '' ? $name : null;
    }

    public function getSkillNumber(): ?int
    {
        $n = $this->validated('skill_number');
        return $n !== null ? (int) $n : null;
    }

    public function getForce(): bool
    {
        return (bool) $this->validated('force', false);
    }
}
