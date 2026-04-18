<?php

namespace App\Contracts\Integrations\Telegram;

/**
 * TelegramServiceInterface
 *
 * Abstracts all interaction with tgcli (telegram_tool.py).
 * Handles per-preset session isolation via TELEGRAM_DATA_DIR.
 */
interface TelegramServiceInterface
{
    // -- Authorization --------------------------------------------------------

    /**
     * Get authorization status and account info for a preset.
     *
     * @param int $presetId
     * @return array{authorized: bool, output: string, account: string}
     */
    public function getStatus(int $presetId): array;

    /**
     * Step 0: save API credentials (api_id + api_hash).
     * Only needed if telegram_config.json doesn't exist yet.
     *
     * @param int    $presetId
     * @param string $apiId
     * @param string $apiHash
     * @return array{success: bool, message: string}
     */
    public function authInit(int $presetId, string $apiId, string $apiHash): array;

    /**
     * Step 1: send confirmation code to the phone number.
     *
     * @param int    $presetId
     * @param string $phone
     * @return array{success: bool, authorized: bool, message: string}
     */
    public function authPhone(int $presetId, string $phone): array;

    /**
     * Step 2: submit the confirmation code received in Telegram.
     *
     * @param int    $presetId
     * @param string $code
     * @return array{success: bool, authorized: bool, needs_2fa: bool, message: string}
     */
    public function authCode(int $presetId, string $code): array;

    /**
     * Step 3 (only if 2FA enabled): submit the cloud password.
     *
     * @param int    $presetId
     * @param string $password
     * @return array{success: bool, authorized: bool, message: string}
     */
    public function authPassword(int $presetId, string $password): array;

    /**
     * Delete session files for a preset.
     *
     * @param int $presetId
     * @return void
     */
    public function destroySession(int $presetId): void;

    // -- Telegram commands ----------------------------------------------------

    /**
     * List dialogs.
     *
     * @param int         $presetId
     * @param int         $limit
     * @param string|null $kind  users|groups|channels|null
     * @return string
     */
    public function dialogs(int $presetId, int $limit = 30, ?string $kind = null): string;

    /**
     * Read messages from a dialog / group / channel.
     *
     * @param int    $presetId
     * @param string $target  @username or numeric id
     * @param int    $limit
     * @return string
     */
    public function read(int $presetId, string $target, int $limit = 15): string;

    /**
     * Send a message.
     *
     * @param int    $presetId
     * @param string $target  @username or numeric id
     * @param string $text
     * @return string
     */
    public function send(int $presetId, string $target, string $text): string;

    /**
     * Show dialogs with unread messages.
     *
     * @param int $presetId
     * @param int $limit
     * @return string
     */
    public function unread(int $presetId, int $limit = 20): string;

    /**
     * Search messages in a chat.
     *
     * @param int    $presetId
     * @param string $target  @username or numeric id
     * @param string $query
     * @return string
     */
    public function search(int $presetId, string $target, string $query): string;

    /**
     * Get info about a user or channel.
     *
     * @param int    $presetId
     * @param string $target  @username or numeric id
     * @return string
     */
    public function info(int $presetId, string $target): string;

    /**
     * Mark a dialog as read.
     *
     * @param int    $presetId
     * @param string $target  @username or numeric id
     * @return string
     */
    public function markRead(int $presetId, string $target): string;

    /**
     * Show current account info.
     *
     * @param int $presetId
     * @return string
     */
    public function me(int $presetId): string;

    /**
     * Run a raw tgcli command string.
     * Use for commands not covered by dedicated methods.
     *
     * @param int    $presetId
     * @param string $args  Full argument string passed to tgcli binary
     * @return string
     */
    public function run(int $presetId, string $args): string;

    /**
     * Return data directory path for a given preset.
     *
     * @param int $presetId
     * @return string
     */
    public function dataDir(int $presetId): string;
}
