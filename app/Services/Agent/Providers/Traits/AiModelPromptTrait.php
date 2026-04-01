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
        $finalMessage = $request->getShortcodeManager()
        ->processShortcodes(
            $initialMessage,
            $request->getPreset()->getId()
        );

        $this->dumpPrompt($request->getPreset()->getId(), $finalMessage);

        return $finalMessage;
    }

    private function dumpPrompt(int $presetId, string $prompt): void
    {
        $dir  = storage_path('logs/prompts');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/preset_' . $presetId . '.txt',
            '[' . date('Y-m-d H:i:s') . ']' . PHP_EOL . $prompt . PHP_EOL
        );
    }

}
