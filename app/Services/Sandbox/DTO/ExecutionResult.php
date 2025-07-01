<?php

declare(strict_types=1);

namespace App\Services\Sandbox\DTO;

/**
 * Result of command execution in sandbox
 */
class ExecutionResult
{
    public function __construct(
        public readonly string $output,
        public readonly string $error,
        public readonly int $exitCode,
        public readonly float $executionTime,
        public readonly bool $timedOut = false
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'error' => $this->error,
            'exit_code' => $this->exitCode,
            'execution_time' => $this->executionTime,
            'timed_out' => $this->timedOut,
            'success' => $this->isSuccess(),
        ];
    }
}
