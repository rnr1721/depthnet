<?php

namespace App\Http\Requests\Admin\Journal;

class ClearJournalRequest extends BaseJournalRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}
