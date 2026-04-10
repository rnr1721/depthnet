<?php

namespace App\Http\Requests\Admin\Agent;

use Illuminate\Foundation\Http\FormRequest;

class IndexAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_id' => ['nullable', 'integer'],
        ];
    }

    public function getAgentId(): ?int
    {
        $value = $this->query('agent_id');
        return $value !== null ? (int) $value : null;
    }
}
