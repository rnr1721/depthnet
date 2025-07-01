<?php

namespace App\Exceptions\Sandbox;

/**
 * Thrown when sandbox creation fails
 */
class SandboxCreationException extends SandboxException
{
    public function __construct(string $message, ?string $sandboxId = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Failed to create sandbox: {$message}",
            500,
            $previous,
            $sandboxId
        );
    }
}
