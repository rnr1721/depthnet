<?php

namespace App\Http\Requests\Admin\Agent;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $agentId = (int) $this->route('id');

        return [
            'code'                 => ['required', 'string', 'max:50'],
            'preset_id'            => ['required', 'integer', 'exists:ai_presets,id'],
            'validator_preset_id'  => ['nullable', 'integer', 'exists:ai_presets,id'],
            'max_attempts'         => ['integer', 'min:1', 'max:10'],
            'auto_proceed'         => ['boolean'],
        ];
    }

    public function getCode(): string
    {
        return $this->input('code');
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }

    public function getValidatorPresetId(): ?int
    {
        return $this->input('validator_preset_id') ? (int) $this->input('validator_preset_id') : null;
    }

    public function getMaxAttempts(): int
    {
        return (int) $this->input('max_attempts', 3);
    }

    public function getAutoProceed(): bool
    {
        return (bool) $this->input('auto_proceed', false);
    }
}
