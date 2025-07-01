<?php

namespace App\Exceptions\Sandbox;

/**
 * Thrown when sandbox is not found
 */
class SandboxNotFoundException extends SandboxException
{
    public function __construct(string $sandboxId, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Sandbox '{$sandboxId}' not found",
            404,
            $previous,
            $sandboxId
        );
    }
}
