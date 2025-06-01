<?php

namespace App\Services\Agent\Engines;

trait AiModelTrait
{
    protected function prepareMessage(
        string $initialMessage,
        string $notepadContent = '',
        int $currentDophamineLevel = 5,
        string $commandInstructions = ''
    ): string {
        $currentDateTime = date('Y-m-d H:i:s');
        $initialMessage = str_replace('[[dopamine_level]]', $currentDophamineLevel, $initialMessage);
        $initialMessage = str_replace('[[notepad_content]]', $notepadContent, $initialMessage);
        $initialMessage = str_replace('[[current_datetime]]', $currentDateTime, $initialMessage);
        $initialMessage = str_replace('[[command_instructions]]', $commandInstructions, $initialMessage);
        return $initialMessage;
    }
}
