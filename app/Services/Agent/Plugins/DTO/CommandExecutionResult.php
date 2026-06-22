<?php

namespace App\Services\Agent\Plugins\DTO;

class CommandExecutionResult
{
    public function __construct(
        public readonly array $results,
        public readonly string $formattedMessage,
        public readonly bool $hasErrors,
        public readonly array $pluginExecutionMeta = [],
        public readonly bool $containedLongContextPlugin = false,
    ) {
    }
}
