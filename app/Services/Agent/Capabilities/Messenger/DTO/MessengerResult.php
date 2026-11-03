<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * Result of a messenger operation: a value on success, or a normalized error
 * code plus a human-readable message on failure.
 *
 * Codes are provider-agnostic so callers can branch on them without knowing
 * which messenger is behind the capability:
 *
 *   not_configured  credentials missing or invalid in the capability config
 *   not_authorized  no session / login not finished — needs the auth flow
 *   not_found       target or message does not exist in this account
 *   rate_limited    provider throttling; see $retryAfter (seconds)
 *   busy            the account session is in use by another call; retry soon
 *   invalid         the request itself was rejected (bad args, no media, too large)
 *   unsupported     the provider does not offer this operation
 *   unavailable     the provider process/service could not be reached
 *   error           anything else
 */
final class MessengerResult
{
    public const CODE_NOT_CONFIGURED = 'not_configured';
    public const CODE_NOT_AUTHORIZED = 'not_authorized';
    public const CODE_NOT_FOUND      = 'not_found';
    public const CODE_RATE_LIMITED   = 'rate_limited';
    public const CODE_BUSY           = 'busy';
    public const CODE_INVALID        = 'invalid';
    public const CODE_UNSUPPORTED    = 'unsupported';
    public const CODE_UNAVAILABLE    = 'unavailable';
    public const CODE_ERROR          = 'error';

    private function __construct(
        public readonly bool $success,
        public readonly mixed $value,
        public readonly ?string $error,
        public readonly ?string $code,
        public readonly ?int $retryAfter,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(true, $value, null, null, null);
    }

    public static function fail(string $error, string $code = self::CODE_ERROR, ?int $retryAfter = null): self
    {
        return new self(false, null, $error, $code, $retryAfter);
    }

    /** Whether the failure is transient and the same call may succeed later. */
    public function isRetryable(): bool
    {
        return !$this->success && in_array($this->code, [
            self::CODE_RATE_LIMITED, self::CODE_BUSY, self::CODE_UNAVAILABLE,
        ], true);
    }
}
