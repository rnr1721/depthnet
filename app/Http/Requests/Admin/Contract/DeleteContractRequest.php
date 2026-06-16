<?php

namespace App\Http\Requests\Admin\Contract;

class DeleteContractRequest extends BaseContractRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}
