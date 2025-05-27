<?php

namespace App\Services\Agent\Plugins\DTO;

class ParsedCommand
{
    public function __construct(
        public readonly string $plugin,
        public readonly string $method,
        public readonly string $content,
        public readonly int $position
    ) {
    }
}
