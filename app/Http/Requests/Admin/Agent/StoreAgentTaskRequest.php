<?php

namespace App\Http\Requests\Admin\Agent;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_id'      => ['required', 'integer', 'exists:agents,id'],
            'title'         => ['required', 'string', 'max:500'],
            'description'   => ['nullable', 'string'],
            'assigned_role' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function getAgentId(): int
    {
        return (int) $this->input('agent_id');
    }

    public function getTitle(): string
    {
        return $this->input('title');
    }

    public function getDescription(): ?string
    {
        return $this->input('description') ?: null;
    }

    public function getAssignedRole(): ?string
    {
        return $this->input('assigned_role') ?: null;
    }
}
