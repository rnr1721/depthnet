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
        $memoryContent = empty($notepadContent) ? 'Now your memory is empty' : $notepadContent;
        $currentDateTime = date('Y-m-d H:i:s');
        $initialMessage = str_replace('[[dopamine_level]]', $currentDophamineLevel, $initialMessage);
        $initialMessage = str_replace('[[notepad_content]]', $memoryContent, $initialMessage);
        $initialMessage = str_replace('[[current_datetime]]', $currentDateTime, $initialMessage);
        $initialMessage = str_replace('[[command_instructions]]', $commandInstructions, $initialMessage);
        return $initialMessage;
    }
}
