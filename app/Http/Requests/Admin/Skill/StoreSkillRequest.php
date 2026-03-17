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
            'description' => 'nullable|string|max:500',
            'first_item'  => 'nullable|string',
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
}
