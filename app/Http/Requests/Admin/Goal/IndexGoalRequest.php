<?php

// IndexGoalRequest.php

namespace App\Http\Requests\Admin\Goal;

use Illuminate\Foundation\Http\FormRequest;

class IndexGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'     => ['nullable', 'integer'],
            'status_filter' => ['nullable', 'string', 'in:active,paused,done,all'],
        ];
    }

    public function getPresetId(): ?int
    {
        $value = $this->query('preset_id');
        return $value !== null ? (int) $value : null;
    }

    public function getStatusFilter(): string
    {
        return $this->query('status_filter', 'all');
    }
}
