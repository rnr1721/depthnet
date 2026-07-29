<?php

namespace App\Http\Requests\Admin\Behavior;

/**
 * Lifecycle transitions (promote/retire/revoke) all carry just a preset. The
 * target status is fixed by the route action, not user input. The legality of
 * the transition itself (e.g. you can't retire a hypothesis) is enforced by
 * BehaviorPatternService::transition() against its legal-transition table — not
 * here.
 */
class TransitionBehaviorRequest extends BaseBehaviorRequest
{
    public function rules(): array
    {
        return array_merge($this->presetRules(), [
            'reason' => 'nullable|string|max:255',
        ]);
    }
}
