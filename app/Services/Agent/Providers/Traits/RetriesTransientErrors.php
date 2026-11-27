<?php

namespace App\Services\Agent\Providers\Traits;

use App\Services\Agent\Errors\TransientErrorClassifier;
use App\Services\Agent\Status\TracksAgentStatus;
use Illuminate\Http\Client\PendingRequest;

/**
 * First line of defence against an unstable connection: retry the HTTP call
 * itself, inside the same cycle. Nothing above it is rebuilt — context, RAG,
 * voices, one-shot flags (memo, reflect, compact) stay as they are.
 *
 * Usage in an engine:
 *   $response = $this->withTransientRetry(
 *       $this->http->withHeaders(...)->timeout(...)
 *   )->post($url, $data);
 *
 * throw: false — after the last attempt the failed Response is returned as
 * before, so the engine's existing `$response->failed()` handling is unchanged.
 * A ConnectionException after the last attempt is still thrown (as before).
 *
 * Delays: config('ai.global.retry.delays_ms'), default [2000, 5000, 12000]
 * (3 retries, ~19s total). Empty array = retries off.
 */
trait RetriesTransientErrors
{
    use TracksAgentStatus;

    protected function withTransientRetry(PendingRequest $request): PendingRequest
    {
        $delays = config('ai.global.retry.delays_ms', [2000, 5000, 12000]);

        if (!is_array($delays) || $delays === []) {
            return $request;
        }

        $total   = count($delays);
        $attempt = 0;

        return $request->retry(
            $delays,
            0,
            function (\Throwable $e) use (&$attempt, $total): bool {
                if (!TransientErrorClassifier::isTransientException($e)) {
                    return false;
                }

                $attempt++;

                // Visible in the status bar: "думает · повтор 1/3 · нет сети"
                $this->annotateStatus('retry:' . $attempt . '/' . $total . ':' . TransientErrorClassifier::reason($e));

                if (property_exists($this, 'logger') && $this->logger) {
                    $this->logger->warning(static::class . ': transient error, retrying', [
                        'attempt' => $attempt,
                        'of'      => $total,
                        'error'   => $e->getMessage(),
                    ]);
                }

                return true;
            },
            throw: false,
        );
    }

    /**
     * error_kind metadata for a failed ModelResponseDTO, so the job layer
     * knows whether to schedule a cycle retry.
     */
    protected function errorKindMeta(?int $httpStatus, string $message): array
    {
        $kind = TransientErrorClassifier::isQuotaExhausted($message)
            ? TransientErrorClassifier::KIND_FATAL
            : ($httpStatus !== null
            ? (TransientErrorClassifier::isTransientStatus($httpStatus)
                ? TransientErrorClassifier::KIND_TRANSIENT
                : TransientErrorClassifier::KIND_FATAL)
            : TransientErrorClassifier::classifyMessage($message));

        return array_filter([
            'error_kind'  => $kind,
            'http_status' => $httpStatus,
        ], fn ($v) => $v !== null);
    }
}
