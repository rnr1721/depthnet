<?php

namespace App\Http\Requests\Admin\Agent;

use Illuminate\Foundation\Http\FormRequest;

class IndexAgentTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_id'      => ['nullable', 'integer', 'exists:agents,id'],
            'status_filter' => ['nullable', 'string', 'in:pending,in_progress,validating,done,failed,escalated,all'],
        ];
    }

    public function getAgentId(): ?int
    {
        $value = $this->input('agent_id');
        return $value !== null ? (int) $value : null;
    }

    public function getStatusFilter(): ?string
    {
        return $this->query('status_filter');
    }
}
