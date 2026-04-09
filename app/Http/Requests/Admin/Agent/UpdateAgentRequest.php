<?php

namespace App\Http\Requests\Admin\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $agentId = (int) $this->route('id');

        return [
            'name'              => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string', 'max:1000'],
            'code'              => ['nullable', 'string', 'max:50', Rule::unique('agents', 'code')->ignore($agentId)],
            'planner_preset_id' => ['required', 'integer', 'exists:ai_presets,id'],
            'is_active'         => ['boolean'],
        ];
    }

    public function getName(): string
    {
        return $this->input('name');
    }

    public function getDescription(): ?string
    {
        return $this->input('description') ?: null;
    }

    public function getCode(): ?string
    {
        return $this->input('code') ?: null;
    }

    public function getPlannerPresetId(): int
    {
        return (int) $this->input('planner_preset_id');
    }

    public function getIsActive(): bool
    {
        return (bool) $this->input('is_active', true);
    }
}
