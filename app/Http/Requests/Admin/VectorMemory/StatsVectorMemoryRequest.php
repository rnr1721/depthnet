<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for getting vector memory stats
 */
class StatsVectorMemoryRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return $this->presetValidationRules();
    }
}
