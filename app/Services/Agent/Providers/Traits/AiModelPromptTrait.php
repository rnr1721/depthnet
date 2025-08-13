<?php

namespace App\Services\Agent\Providers\Traits;

use App\Contracts\Agent\AiModelRequestInterface;

trait AiModelPromptTrait
{
    protected function prepareMessage(
        AiModelRequestInterface $request
    ): string {
        $initialMessage = $request->getPreset()->getSystemPrompt();
        if (empty($initialMessage)) {
            $initialMessage = $this->config['system_prompt'] ?? '';
        }
        $finalMessage = $request->getShortcodeManager()->processShortcodes($initialMessage);
        return $finalMessage;
    }
}
