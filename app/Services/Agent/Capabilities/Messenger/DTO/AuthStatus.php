<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * Whether the messenger account is usable, and which account it is.
 *
 * When not authorized, $code says why (a MessengerResult::CODE_* value):
 * not_configured → fix the capability config; not_authorized → run the login
 * flow; anything else → transient or unexpected, see $message.
 */
final class AuthStatus
{
    public function __construct(
        public readonly bool $authorized,
        public readonly ?Peer $account = null,
        public readonly ?string $message = null,
        public readonly ?string $code = null,
    ) {
    }

    public static function authorized(Peer $account): self
    {
        return new self(true, $account);
    }

    public static function notAuthorized(string $message, string $code = MessengerResult::CODE_NOT_AUTHORIZED): self
    {
        return new self(false, null, $message, $code);
    }

    public function toArray(): array
    {
        return [
            'authorized' => $this->authorized,
            'account'    => $this->account?->toArray(),
            'message'    => $this->message,
            'code'       => $this->code,
        ];
    }
}
