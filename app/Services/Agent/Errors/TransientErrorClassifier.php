<?php

namespace App\Services\Agent\Errors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * "Is it worth trying again?" — one place for that decision.
 *
 * transient: network down, DNS, connection reset/refused, connect timeout,
 *            rate limit, provider overload / 5xx
 * fatal:     auth, balance, bad request, content refusal — retrying changes nothing
 *
 * READ timeout ("Operation timed out ... bytes received") is split:
 *   - HTTP level (isTransientException): NOT retried — the request reached the
 *     provider, the model may still be generating; an immediate retry may
 *     generate (and bill) the same long answer twice.
 *   - cycle level (classifyMessage): transient — on a flaky connection a stalled
 *     read is usually the network; one cycle retry after 30s+ is reasonable.
 */
final class TransientErrorClassifier
{
    public const KIND_TRANSIENT = 'transient';
    public const KIND_FATAL     = 'fatal';

    /** HTTP statuses worth retrying. 529 = Anthropic "overloaded". */
    public const TRANSIENT_STATUSES = [408, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524, 529];

    public static function isTransientException(\Throwable $e): bool
    {
        if ($e instanceof RequestException) {
            // OpenAI sends "out of credits" as 429 — that's not going away by itself
            if (self::isQuotaExhausted((string) $e->response?->body())) {
                return false;
            }
            return self::isTransientStatus($e->response?->status());
        }

        if ($e instanceof ConnectionException) {
            return !self::isReadTimeout($e->getMessage());
        }

        return false;
    }

    public static function isTransientStatus(?int $status): bool
    {
        return $status !== null && in_array($status, self::TRANSIENT_STATUSES, true);
    }

    /**
     * Classify by text — for errors that reach us only as a message
     * (engines that don't set error_kind, exceptions inside think()).
     */
    public static function classifyMessage(?string $message): string
    {
        $m = (string) $message;

        if (self::isQuotaExhausted($m)) {
            return self::KIND_FATAL;
        }

        // "API Error (503)", "API Error (503, unknown)", "HTTP Error: 503 - ..."
        if (preg_match('/\((\d{3})[,)]/', $m, $mm) || preg_match('/error:?\s+(\d{3})\s+-/i', $m, $mm)) {
            return self::isTransientStatus((int) $mm[1]) ? self::KIND_TRANSIENT : self::KIND_FATAL;
        }

        $transient = '/(could not resolve|connection (refused|reset|timed out)|operation timed out|failed to connect|'
            . 'network|unreachable|temporar|overload|rate limit|too many requests|'
            . 'server error|try again|error contacting|ssl|curl error (6|7|35|52|56))/i';

        return preg_match($transient, $m) ? self::KIND_TRANSIENT : self::KIND_FATAL;
    }

    /** Short machine reason for the status line. */
    public static function reason(\Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $status = $e->response?->status();
            return match (true) {
                $status === 429                  => 'rate_limit',
                in_array($status, [503, 529])    => 'overloaded',
                default                          => 'http_' . $status,
            };
        }

        return 'network';
    }

    /** Out of money / quota: retrying won't help until someone tops up. */
    public static function isQuotaExhausted(string $text): bool
    {
        return (bool) preg_match('/insufficient[_ ](quota|balance)|billing|credit balance is too low|exceeded your current quota/i', $text);
    }

    private static function isReadTimeout(string $message): bool
    {
        return (bool) preg_match('/operation timed out.*(bytes received|out of)/i', $message);
    }
}
