<?php

namespace App\Services\Agent\Capabilities\Messenger\Drivers\Telegram;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Psr\Log\LoggerInterface;

/**
 * Runs tgcli (≥ 1.1) and parses its --json output.
 *
 * The command is passed as an argv ARRAY, never a shell string: no shell is
 * involved, so nothing in a target, message text or query can be interpreted
 * as shell syntax. This replaces TelegramService::run(), which concatenated
 * arguments into a shell_exec() string.
 *
 * stdout carries exactly one JSON object (tgcli guarantees it with --json);
 * stderr is kept separate and only used for diagnostics.
 */
class TgcliRunner
{
    /** Seconds reserved so tgcli's own lock timeout fires before ours. */
    private const LOCK_HEADROOM = 5;

    public function __construct(
        protected ProcessFactory $process,
        protected LoggerInterface $logger,
        protected string $binary = 'telegram',
        protected int $timeout = 60,
    ) {
    }

    /** The tgcli executable this runner calls. */
    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * @param string[]              $args Command and its arguments, e.g. ['send', '@x', 'hi'].
     * @param array<string, string> $env  Extra environment (TELEGRAM_DATA_DIR, credentials).
     */
    public function call(array $args, array $env = [], ?int $timeout = null): TgcliResponse
    {
        $timeout = $timeout ?? $this->timeout;

        $env += [
            'TELEGRAM_LOCK_TIMEOUT' => (string) max(1, $timeout - self::LOCK_HEADROOM),
        ];

        $command = array_merge([$this->binary, '--json'], array_map('strval', $args));

        try {
            $result = $this->process
                ->timeout($timeout)
                ->env($env)
                ->run($command);
        } catch (ProcessTimedOutException $e) {
            $this->logger->warning('tgcli: timed out', ['command' => $args[0] ?? null, 'timeout' => $timeout]);
            return TgcliResponse::failure("Telegram did not respond within {$timeout}s.", 'timeout', 124);
        } catch (\Throwable $e) {
            $this->logger->error('tgcli: could not run', ['command' => $args[0] ?? null, 'error' => $e->getMessage()]);
            return TgcliResponse::failure('Could not run tgcli: ' . $e->getMessage(), 'process', 127);
        }

        $exit = (int) $result->exitCode();
        $json = $this->decode($result->output());

        if ($json === null) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());
            $this->logger->error('tgcli: no JSON in output', [
                'command' => $args[0] ?? null,
                'exit'    => $exit,
                'output'  => mb_substr($detail, 0, 500),
            ]);
            return TgcliResponse::failure(
                'tgcli returned no usable output' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : '.')
                . ' Is tgcli ≥ 1.1 installed?',
                'process',
                $exit ?: 1,
            );
        }

        if (($json['ok'] ?? false) === true) {
            unset($json['ok']);
            return TgcliResponse::success($json, $exit);
        }

        return TgcliResponse::failure(
            (string) ($json['error'] ?? 'Unknown tgcli error'),
            (string) ($json['code'] ?? 'error'),
            $exit ?: 1,
            isset($json['retry_after']) ? (int) $json['retry_after'] : null,
        );
    }

    /**
     * tgcli prints one JSON object. Tolerate stray lines before it (e.g. a
     * library warning that slipped through) by falling back to the last line.
     */
    private function decode(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        $data = json_decode($output, true);
        if (is_array($data)) {
            return $data;
        }

        $lines = preg_split('/\R/', $output) ?: [];
        $data  = json_decode((string) end($lines), true);

        return is_array($data) ? $data : null;
    }
}
