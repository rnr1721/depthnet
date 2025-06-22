<?php

namespace App\Http\Requests\Admin\VectorMemory;

/**
 * Request validation for exporting vector memories
 */
class ExportVectorMemoryRequest extends BaseVectorMemoryRequest
{
    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return $this->presetValidationRules();
    }
}
