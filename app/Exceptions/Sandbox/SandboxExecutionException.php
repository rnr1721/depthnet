<?php

namespace App\Exceptions\Sandbox;

/**
 * Thrown when command execution fails
 */
class SandboxExecutionException extends SandboxException
{
    public function __construct(
        string $message,
        ?string $sandboxId = null,
        public readonly ?string $command = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            "Execution failed: {$message}",
            500,
            $previous,
            $sandboxId
        );
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }
}
