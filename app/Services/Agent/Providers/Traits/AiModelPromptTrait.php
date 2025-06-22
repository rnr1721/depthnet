<?php

namespace App\Services\Agent\Providers\Traits;

use App\Contracts\Agent\AiModelRequestInterface;

trait AiModelPromptTrait
{
    protected function prepareMessage(
        AiModelRequestInterface $request
    ): string {
        $initialMessage = $request->getPreset()->getSystemPrompt();
        $finalMessage = $request->getShortcodeManager()->processShortcodes($initialMessage);
        return $finalMessage;
    }
}
