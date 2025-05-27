<?php

namespace App\Contracts\Agent;

/**
 * Interface AIModelInterface
 *
 * @package App\Contracts\Agent
 *
 * @property string $serverUrl
 * @property array $config
 */
interface AIModelInterface
{
    /**
     * Generate a response based on the context
     *
     * @param array $context Array of messages [['role' => '...', 'content' => '...'], ...]
     * @param string $initialMessage Initial message to be sent to the model
     * @param string $notepadContent Notepad content
     * @param int $currentDophamineLevel Current dopamine level
     * @param string $commandInstructions
     * @return string
     */
    public function generate(
        array $context,
        string $initialMessage,
        string $notepadContent = '',
        int $currentDophamineLevel = 5,
        string $commandInstructions = ''
    ): string;

    /**
     * Get the model name
     *
     * @return string
     */
    public function getName(): string;
}
