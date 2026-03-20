<?php

namespace App\Http\Requests\Admin\Person;

use Illuminate\Foundation\Http\FormRequest;

class IndexPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return ['preset_id' => ['nullable', 'integer']];
    }
    public function getPresetId(): ?int
    {
        $v = $this->query('preset_id');
        return $v !== null ? (int) $v : null;
    }
}
