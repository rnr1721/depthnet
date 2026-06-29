<?php

namespace App\Http\Requests\Admin\Behavior;

class DeleteBehaviorRequest extends BaseBehaviorRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}
