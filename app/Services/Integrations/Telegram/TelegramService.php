<?php

namespace App\Services\Integrations\Telegram;

use App\Contracts\Integrations\Telegram\TelegramServiceInterface;

/**
 * TelegramService
 *
 * All interaction with tgcli lives here.
 * Per-preset session isolation is achieved via TELEGRAM_DATA_DIR env variable.
 */
class TelegramService implements TelegramServiceInterface
{
    public function __construct(
        protected string $binary,
        protected string $baseDataDir,
        protected int    $timeout,
    ) {
    }

    // -- Authorization --------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getStatus(int $presetId): array
    {
        $output     = $this->run($presetId, 'me');
        $authorized = !str_contains($output, '[ERROR]') && !str_contains($output, '[WARN]');
        $account    = '';

        if ($authorized) {
            $lines   = array_filter(array_map('trim', explode("\n", $output)));
            $account = collect($lines)
                ->first(fn ($l) => str_starts_with($l, '[user]')
                    || str_starts_with($l, 'Username:')
                    || str_starts_with($l, 'ID:')) ?? '';
        }

        return [
            'authorized' => $authorized,
            'output'     => $output,
            'account'    => $account,
        ];
    }

    /**
     * @inheritDoc
     */
    public function authInit(int $presetId, string $apiId, string $apiHash): array
    {
        $output = $this->run(
            $presetId,
            'auth:init ' . escapeshellarg($apiId) . ' ' . escapeshellarg($apiHash)
        );

        return $this->parseResult($output);
    }

    /**
     * @inheritDoc
     */
    public function authPhone(int $presetId, string $phone): array
    {
        $output = $this->run($presetId, 'auth:phone ' . escapeshellarg($phone));
        $result = $this->parseResult($output);

        $result['authorized'] = $result['success']
            && str_contains($output, 'Already authorized');

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function authCode(int $presetId, string $code): array
    {
        $output = $this->run($presetId, 'auth:code ' . escapeshellarg($code));
        $result = $this->parseResult($output);

        $result['needs_2fa']  = $result['success'] && str_contains($output, '[2FA]');
        $result['authorized'] = $result['success'] && !$result['needs_2fa']
            && str_contains($output, '[OK]');

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function authPassword(int $presetId, string $password): array
    {
        $output = $this->run($presetId, 'auth:password ' . escapeshellarg($password));
        $result = $this->parseResult($output);

        $result['authorized'] = $result['success'] && str_contains($output, '[OK]');

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function destroySession(int $presetId): void
    {
        $dir   = $this->dataDir($presetId);
        $files = [
            $dir . '/telegram_session.session',
            $dir . '/telegram_auth_state.json',
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    // -- Telegram commands ----------------------------------------------------

    /**
     * @inheritDoc
     */
    public function dialogs(int $presetId, int $limit = 30, ?string $kind = null): string
    {
        $cmd = 'dialogs ' . $limit;
        if ($kind !== null) {
            $cmd .= ' ' . escapeshellarg($kind);
        }
        return $this->run($presetId, $cmd);
    }

    /**
     * @inheritDoc
     */
    public function read(int $presetId, string $target, int $limit = 15): string
    {
        return $this->run(
            $presetId,
            'read ' . escapeshellarg($target) . ' ' . $limit
        );
    }

    /**
     * @inheritDoc
     */
    public function send(int $presetId, string $target, string $text): string
    {
        return $this->run(
            $presetId,
            'send ' . escapeshellarg($target) . ' ' . escapeshellarg($text)
        );
    }

    /**
     * @inheritDoc
     */
    public function unread(int $presetId, int $limit = 20): string
    {
        return $this->run($presetId, 'unread ' . $limit);
    }

    /**
     * @inheritDoc
     */
    public function search(int $presetId, string $target, string $query): string
    {
        return $this->run(
            $presetId,
            'search ' . escapeshellarg($target) . ' ' . escapeshellarg($query)
        );
    }

    /**
     * @inheritDoc
     */
    public function info(int $presetId, string $target): string
    {
        return $this->run($presetId, 'info ' . escapeshellarg($target));
    }

    /**
     * @inheritDoc
     */
    public function markRead(int $presetId, string $target): string
    {
        return $this->run($presetId, 'mark_read ' . escapeshellarg($target));
    }

    /**
     * @inheritDoc
     */
    public function me(int $presetId): string
    {
        return $this->run($presetId, 'me');
    }

    /**
     * @inheritDoc
     */
    public function run(int $presetId, string $args): string
    {
        $dataDir = $this->dataDir($presetId);
        $env     = 'TELEGRAM_DATA_DIR=' . $dataDir;
        $binary  = escapeshellcmd($this->binary);
        $command = $env . ' timeout ' . $this->timeout . ' ' . $binary . ' ' . $args . ' 2>&1';

        $output = shell_exec($command);

        return trim($output ?? '[ERROR] Process failed or timed out.');
    }

    /**
     * @inheritDoc
     */
    public function dataDir(int $presetId): string
    {
        return rtrim($this->baseDataDir, '/') . '/preset_' . $presetId;
    }

    // -- Internal helpers -----------------------------------------------------

    /**
     * Parse tgcli output into a standard result array.
     *
     * @param string $output
     * @return array{success: bool, message: string}
     */
    private function parseResult(string $output): array
    {
        $success = !str_contains($output, '[ERROR]');

        return [
            'success' => $success,
            'message' => $output,
        ];
    }
}
