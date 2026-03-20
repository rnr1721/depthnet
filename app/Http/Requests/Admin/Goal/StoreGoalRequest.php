<?php

namespace App\Http\Requests\Admin\Goal;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'  => ['required', 'integer'],
            'title'      => ['required', 'string', 'max:500'],
            'motivation' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function getPresetId(): int
    {
        return (int) $this->input('preset_id');
    }
    public function getTitle(): string
    {
        return $this->input('title');
    }
    public function getMotivation(): ?string
    {
        return $this->input('motivation') ?: null;
    }
}
