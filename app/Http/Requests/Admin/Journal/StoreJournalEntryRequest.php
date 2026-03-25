<?php

namespace App\Http\Requests\Admin\Journal;

class StoreJournalEntryRequest extends BaseJournalRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'content' => 'required|string|max:5000',
        ]);
    }

    public function getJournalEntryContent(): string
    {
        return $this->validated('content');
    }
}
