<?php

namespace App\Services\Agent\Plugins\DTO;

class CommandResult
{
    public function __construct(
        public readonly ParsedCommand $command,
        public readonly string $result,
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly array $executionMeta = []
    ) {
    }
}
