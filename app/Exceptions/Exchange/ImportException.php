<?php

namespace App\Exceptions\Exchange;

/**
 * Thrown when an import cannot proceed or fails mid-transaction.
 *
 * On a blocking preflight error the exception carries the accumulated error
 * list so the caller can surface the full report (not just the first problem).
 * Mid-transaction failures (a service returning success:false) also throw this,
 * which rolls back the single import transaction — nothing is left half-written.
 */
class ImportException extends \RuntimeException
{
    /** @var string[] */
    public array $errors;

    /**
     * @param string[] $errors  Full accumulated error list (may be one item).
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors ?: [$message];
    }
}
