<?php

namespace App\Http\Requests\Admin\Journal;

class SearchJournalRequest extends BaseJournalRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'query' => 'required|string|min:2|max:200',
        ]);
    }

    public function getSearchQuery(): string
    {
        return $this->validated('query');
    }
}
