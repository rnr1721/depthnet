<?php

namespace App\Http\Requests\Admin\Goal;

use Illuminate\Foundation\Http\FormRequest;

class StoreProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'   => ['required', 'integer'],
            'goal_number' => ['required', 'integer', 'min:1'],
            'content'     => ['required', 'string'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getGoalNumber(): int
    {
        return (int) $this->input('goal_number');
    }
    public function getProgressContent(): string
    {
        return $this->input('content');
    }
}
