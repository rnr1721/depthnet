<?php

namespace App\Http\Requests\Admin\Journal;

class DeleteJournalEntryRequest extends BaseJournalRequest
{
    public function rules(): array
    {
        return $this->presetRules();
    }
}
