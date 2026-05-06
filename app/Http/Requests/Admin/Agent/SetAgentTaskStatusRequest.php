<?php

namespace App\Http\Requests\Admin\Agent;

use Illuminate\Foundation\Http\FormRequest;

class SetAgentTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'integer', 'exists:agents,id'],
            'status'   => ['required', 'string', 'in:pending,done,failed,escalated'],
        ];
    }

    public function getAgentId(): int
    {
        return (int) $this->input('agent_id');
    }

    public function getStatus(): string
    {
        return $this->input('status');
    }
}
