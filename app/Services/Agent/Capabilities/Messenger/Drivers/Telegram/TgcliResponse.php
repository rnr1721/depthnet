<?php

namespace App\Services\Agent\Capabilities\Messenger\Drivers\Telegram;

/**
 * Parsed result of one tgcli --json invocation.
 *
 * $code is tgcli's own error code (no_session, flood_wait, ...) on failure, or
 * 'process' when tgcli could not be run or did not produce JSON.
 */
final class TgcliResponse
{
    /**
     * @param array<string, mixed> $data Decoded JSON (without the "ok" key) on success.
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly ?string $error,
        public readonly ?string $code,
        public readonly ?int $retryAfter,
        public readonly int $exitCode,
    ) {
    }

    public static function success(array $data, int $exitCode = 0): self
    {
        return new self(true, $data, null, null, null, $exitCode);
    }

    public static function failure(string $error, string $code, int $exitCode = 1, ?int $retryAfter = null): self
    {
        return new self(false, [], $error, $code, $retryAfter, $exitCode);
    }
}
