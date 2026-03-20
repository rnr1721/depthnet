<?php

namespace App\Http\Requests\Admin\Goal;

use Illuminate\Foundation\Http\FormRequest;

class SetGoalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id' => ['required', 'integer'],
            'status'    => ['required', 'string', 'in:active,paused,done'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getStatus(): string
    {
        return $this->input('status');
    }
}
