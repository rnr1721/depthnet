<?php

namespace App\Services\Agent\Plugins\DTO;

class CommandExecutionResult
{
    public function __construct(
        public readonly array $results,
        public readonly string $formattedMessage,
        public readonly bool $hasErrors
    ) {
    }
}
