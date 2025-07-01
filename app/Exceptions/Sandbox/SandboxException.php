<?php

namespace App\Exceptions\Sandbox;

/**
 * Base sandbox-related exception
 */
class SandboxException extends \Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $sandboxId = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getSandboxId(): ?string
    {
        return $this->sandboxId;
    }
}
