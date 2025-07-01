<?php

namespace App\Exceptions\Sandbox;

/**
 * Thrown when command execution times out
 */
class SandboxTimeoutException extends SandboxException
{
    public function __construct(
        string $message,
        int $timeout,
        ?string $sandboxId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            "Operation timed out after {$timeout}s: {$message}",
            408,
            $previous,
            $sandboxId
        );
    }
}
