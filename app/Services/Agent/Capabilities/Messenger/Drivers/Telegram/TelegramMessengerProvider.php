<?php

namespace App\Services\Agent\Capabilities\Messenger\Drivers\Telegram;

use App\Contracts\Agent\Capabilities\AuthenticatesInteractivelyInterface;
use App\Contracts\Agent\Capabilities\DescribesChatsInterface;
use App\Contracts\Agent\Capabilities\DownloadsMediaInterface;
use App\Contracts\Agent\Capabilities\ListsContactsInterface;
use App\Contracts\Agent\Capabilities\ListsDialogsInterface;
use App\Contracts\Agent\Capabilities\MarksReadInterface;
use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Contracts\Agent\Capabilities\ReceivesMessagesInterface;
use App\Contracts\Agent\Capabilities\RepliesToMessagesInterface;
use App\Contracts\Agent\Capabilities\SearchesMessagesInterface;
use App\Services\Agent\Capabilities\Messenger\DTO\AuthStatus;
use App\Services\Agent\Capabilities\Messenger\DTO\AuthStep;
use App\Services\Agent\Capabilities\Messenger\DTO\ChatHistory;
use App\Services\Agent\Capabilities\Messenger\DTO\ChatInfo;
use App\Services\Agent\Capabilities\Messenger\DTO\DownloadedMedia;
use App\Services\Agent\Capabilities\Messenger\DTO\MediaInfo;
use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;
use App\Services\Agent\Capabilities\Messenger\DTO\Peer;
use App\Services\Agent\Capabilities\Messenger\DTO\SentMessage;
use App\Services\Agent\Capabilities\Messenger\DTO\UpdatesBatch;
use App\Services\Agent\Capabilities\Messenger\Traits\ProvidesInboundFields;
use Psr\Log\LoggerInterface;

/**
 * Telegram via a real user account (MTProto), driven by tgcli ≥ 1.1.
 *
 * Session isolation: each preset has its own data dir, <base>/preset_<id> —
 * the same layout the old TelegramService used, so existing sessions keep
 * working after the switch.
 *
 * Credentials: api_id/api_hash from the capability config are passed to tgcli
 * as environment variables on every call; nothing is written to disk. When
 * they are left empty, tgcli falls back to telegram_config.json in the data
 * dir — which is where pre-capability installs saved them via auth:init.
 */
class TelegramMessengerProvider implements
    MessengerProviderInterface,
    AuthenticatesInteractivelyInterface,
    ReceivesMessagesInterface,
    RepliesToMessagesInterface,
    ListsDialogsInterface,
    ListsContactsInterface,
    SearchesMessagesInterface,
    MarksReadInterface,
    DescribesChatsInterface,
    DownloadsMediaInterface
{
    use ProvidesInboundFields;

    /** Baseline scans and downloads can take a while on large accounts. */
    private const SLOW_TIMEOUT = 120;

    private const CURSOR_FILE = 'updates_cursor.pending.json';

    /** Files that make up a login; removed by logout(). Credentials file is kept. */
    /** Telegram service notifications (login codes, security alerts). */
    private const SERVICE_CHATS = ['777000'];

    private const SESSION_FILES = [
        'telegram_session.session',
        'telegram_session.session-journal',
        'telegram_auth_state.json',
        'telegram_qr.json',
        'telegram_qr.log',
        'updates_state.json',
        self::CURSOR_FILE,
    ];

    private string $apiId;
    private string $apiHash;
    private int $downloadMaxMb;

    /**
     * @param array<string, mixed> $config   Capability config for this preset.
     * @param int|null             $presetId Null only for the registry prototype.
     */
    public function __construct(
        protected TgcliRunner $runner,
        protected LoggerInterface $logger,
        protected string $baseDataDir,
        protected array $config = [],
        protected ?int $presetId = null,
    ) {
        $this->apiId         = trim((string) ($config['api_id'] ?? ''));
        $this->apiHash       = trim((string) ($config['api_hash'] ?? ''));
        $this->downloadMaxMb = (int) ($config['download_max_mb'] ?? 20);
    }

    // ── CapabilityProviderInterface ──────────────────────────────────────────

    public function getDriverName(): string
    {
        return 'telegram';
    }

    public function getDisplayName(): string
    {
        return 'Telegram (user account)';
    }

    public function getConfigFields(): array
    {
        return array_merge([
            'api_id' => [
                'type'        => 'text',
                'label'       => 'API ID',
                'description' => 'From my.telegram.org → API development tools. Leave both API fields '
                    . 'empty to keep using credentials saved by an earlier setup.',
                'placeholder' => '12345678',
                'required'    => false,
            ],
            'api_hash' => [
                'type'        => 'password',
                'label'       => 'API hash',
                'placeholder' => '0123456789abcdef0123456789abcdef',
                'required'    => false,
            ],
            'download_max_mb' => [
                'type'        => 'number',
                'label'       => 'Max media download (MB)',
                'description' => 'Voice messages and photos are downloaded for speech recognition '
                    . 'and vision. Larger files are refused. 0 = no limit.',
                'min'         => 0,
                'max'         => 2000,
                'required'    => false,
            ],
        ], $this->inboundFields());
    }

    public function getDefaultConfig(): array
    {
        return array_merge([
            'api_id'          => '',
            'api_hash'        => '',
            'download_max_mb' => 20,
        ], $this->inboundDefaults());
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        $id     = trim((string) ($config['api_id'] ?? ''));
        $hash   = trim((string) ($config['api_hash'] ?? ''));

        if (($id === '') !== ($hash === '')) {
            $errors[$id === '' ? 'api_id' : 'api_hash'] = 'Fill in both API ID and API hash, or leave both empty.';
        }
        if ($id !== '' && !ctype_digit($id)) {
            $errors['api_id'] = 'API ID is a number.';
        }

        $mb = $config['download_max_mb'] ?? null;
        if ($mb !== null && $mb !== '' && ((int) $mb < 0 || (int) $mb > 2000)) {
            $errors['download_max_mb'] = 'Must be between 0 and 2000.';
        }

        return $errors + $this->validateInbound($config);
    }

    // ── MessengerProviderInterface ───────────────────────────────────────────

    public function status(): AuthStatus
    {
        $r = $this->call(['me']);

        if (!$r->ok) {
            return AuthStatus::notAuthorized((string) $r->error, TgcliMapper::errorCode($r->code));
        }

        return AuthStatus::authorized(TgcliMapper::peer($r->data['user'] ?? []));
    }

    public function resolve(string $query): MessengerResult
    {
        $query = trim($query);
        if ($query === '') {
            return MessengerResult::fail('Query is required.', MessengerResult::CODE_INVALID);
        }

        $r = $this->call(['resolve', $query]);

        return $r->ok
            ? MessengerResult::ok(TgcliMapper::peers($r->data['matches'] ?? []))
            : TgcliMapper::failure($r);
    }

    public function read(string $target, int $limit = 15): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }

        $r = $this->call(['read', trim($target), (string) max(1, min(200, $limit))]);
        if (!$r->ok) {
            return TgcliMapper::failure($r);
        }

        $chat = TgcliMapper::peer($r->data['chat'] ?? []);

        return MessengerResult::ok(new ChatHistory($chat, TgcliMapper::messages($r->data['messages'] ?? [], $chat)));
    }

    public function send(string $target, string $text): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }
        if (trim($text) === '') {
            return MessengerResult::fail('Message text is required.', MessengerResult::CODE_INVALID);
        }

        return $this->sendMessage(trim($target), $text, null);
    }

    // ── RepliesToMessagesInterface ───────────────────────────────────────────

    public function reply(string $target, string $messageId, string $text): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }
        if (trim($text) === '') {
            return MessengerResult::fail('Message text is required.', MessengerResult::CODE_INVALID);
        }

        $id = ltrim(trim($messageId), '#');
        if (!ctype_digit($id) || (int) $id < 1) {
            return MessengerResult::fail("Not a message id: '{$messageId}'.", MessengerResult::CODE_INVALID);
        }

        // tgcli before 1.4 has no --reply-to and would send it as part of the
        // text — deliver the words plainly instead of garbling them.
        return $this->sendMessage(trim($target), $text, $this->supportsReplies() ? $id : null);
    }

    private function sendMessage(string $target, string $text, ?string $replyTo): MessengerResult
    {
        $args = ['send', $target];
        if ($replyTo !== null) {
            array_push($args, '--reply-to', $replyTo, '--');
        } elseif (str_starts_with($text, '-') && $this->supportsReplies()) {
            // 1.4+ reads options after the target; `--` keeps "-…" text verbatim.
            $args[] = '--';
        }
        $args[] = $text;

        $r = $this->call($args);
        if (!$r->ok) {
            return TgcliMapper::failure($r);
        }

        return MessengerResult::ok(new SentMessage(
            TgcliMapper::peer($r->data['chat'] ?? []),
            (string) ($r->data['sent']['id'] ?? ''),
            TgcliMapper::date($r->data['sent']['date'] ?? null),
        ));
    }

    /**
     * tgcli >= 1.4 (send --reply-to). Asked once per binary per process —
     * queue workers live long, so this costs one call, not one per message.
     */
    private function supportsReplies(): bool
    {
        $binary = $this->runner->binary();
        if (!array_key_exists($binary, self::$replySupport)) {
            $r = $this->runner->call(['--version'], [], 15);
            $version = $r->ok ? (string) ($r->data['version'] ?? '0') : '0';
            self::$replySupport[$binary] = version_compare($version, '1.4.0', '>=');
        }

        return self::$replySupport[$binary];
    }

    /** @var array<string, bool> binary => supports --reply-to */
    private static array $replySupport = [];

    /** For tests: forget what was learned about tgcli versions. */
    public static function forgetCapabilities(): void
    {
        self::$replySupport = [];
    }

    // ── ListsDialogsInterface / ListsContactsInterface ───────────────────────

    public function dialogs(int $limit = 30, ?string $kind = null): MessengerResult
    {
        $args = ['dialogs', (string) max(1, min(500, $limit))];

        if ($kind !== null) {
            if (!in_array($kind, [self::KIND_USERS, self::KIND_GROUPS, self::KIND_CHANNELS], true)) {
                return MessengerResult::fail(
                    'Kind must be one of: users, groups, channels.',
                    MessengerResult::CODE_INVALID
                );
            }
            $args[] = $kind;
        }

        $r = $this->call($args);

        return $r->ok
            ? MessengerResult::ok(TgcliMapper::dialogs($r->data['dialogs'] ?? []))
            : TgcliMapper::failure($r);
    }

    public function unread(int $limit = 20): MessengerResult
    {
        $r = $this->call(['unread', (string) max(1, min(500, $limit))]);

        return $r->ok
            ? MessengerResult::ok(TgcliMapper::dialogs($r->data['dialogs'] ?? []))
            : TgcliMapper::failure($r);
    }

    public function contacts(): MessengerResult
    {
        $r = $this->call(['contacts']);

        return $r->ok
            ? MessengerResult::ok(TgcliMapper::peers($r->data['contacts'] ?? []))
            : TgcliMapper::failure($r);
    }

    // ── Search / mark read / info ────────────────────────────────────────────

    public function search(string $target, string $query): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }
        if (trim($query) === '') {
            return MessengerResult::fail('Search query is required.', MessengerResult::CODE_INVALID);
        }

        $r = $this->call(['search', trim($target), trim($query)]);
        if (!$r->ok) {
            return TgcliMapper::failure($r);
        }

        $chat = TgcliMapper::peer($r->data['chat'] ?? []);

        return MessengerResult::ok(new ChatHistory($chat, TgcliMapper::messages($r->data['messages'] ?? [], $chat)));
    }

    public function markRead(string $target): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }

        $r = $this->call(['mark_read', trim($target)]);

        return $r->ok
            ? MessengerResult::ok(TgcliMapper::peer($r->data['chat'] ?? []))
            : TgcliMapper::failure($r);
    }

    public function info(string $target): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }

        $r = $this->call(['info', trim($target)]);
        if (!$r->ok) {
            return TgcliMapper::failure($r);
        }

        return MessengerResult::ok(new ChatInfo(
            TgcliMapper::peer($r->data['entity'] ?? []),
            isset($r->data['members']) ? (int) $r->data['members'] : null,
            isset($r->data['about']) ? (string) $r->data['about'] : null,
        ));
    }

    // ── DownloadsMediaInterface ──────────────────────────────────────────────

    public function download(string $target, string $messageId, ?string $directory = null): MessengerResult
    {
        if (($bad = $this->checkTarget($target)) !== null) {
            return $bad;
        }
        if (!ctype_digit(trim($messageId))) {
            return MessengerResult::fail('Message id must be a number.', MessengerResult::CODE_INVALID);
        }

        $args = ['download', trim($target), trim($messageId)];
        if ($directory !== null && $directory !== '') {
            // Trailing slash tells tgcli "directory, generate the file name".
            $args[] = rtrim($directory, '/') . '/';
        }

        $r = $this->call($args, self::SLOW_TIMEOUT);
        if (!$r->ok) {
            return TgcliMapper::failure($r);
        }

        return MessengerResult::ok(new DownloadedMedia(
            path:      (string) ($r->data['path'] ?? ''),
            media:     TgcliMapper::media($r->data['media'] ?? null) ?? new MediaInfo(MediaInfo::TYPE_OTHER),
            chat:      TgcliMapper::peer($r->data['chat'] ?? []),
            messageId: trim($messageId),
            size:      isset($r->data['size']) ? (int) $r->data['size'] : null,
        ));
    }

    // ── ReceivesMessagesInterface ────────────────────────────────────────────

    public function fetchUpdates(array $cursor, int $limit = 100, int $perDialog = 20): MessengerResult
    {
        if ($this->presetId === null) {
            return $this->unbound();
        }

        // The cursor goes through a file, not argv: on a large account the
        // per-dialog map can exceed the kernel's single-argument size limit.
        // --no-commit: tgcli reads it but never advances it — the caller saves
        // the returned cursor after processing (at-least-once delivery).
        $file = $this->dataDir() . '/' . self::CURSOR_FILE;

        try {
            $this->ensureDataDir();
            file_put_contents($file, json_encode($cursor === [] ? new \stdClass() : $cursor), LOCK_EX);
            @chmod($file, 0600);
        } catch (\Throwable $e) {
            return MessengerResult::fail('Cannot write cursor file: ' . $e->getMessage(), MessengerResult::CODE_ERROR);
        }

        try {
            $r = $this->call([
                'updates',
                '--state', $file,
                '--no-commit',
                '--limit', (string) max(1, $limit),
                '--per-dialog', (string) max(1, $perDialog),
            ], self::SLOW_TIMEOUT);
        } finally {
            @unlink($file);
        }

        if (!$r->ok) {
            return TgcliMapper::failure($r);
        }

        // Telegram's service account sends login codes and security notices.
        // They must never reach an agent, whatever the inbound policy says —
        // a login code in the context is a way into the agent's own account.
        $messages = array_values(array_filter(
            TgcliMapper::messages($r->data['messages'] ?? []),
            fn ($m) => !in_array($m->chat->id, self::SERVICE_CHATS, true),
        ));

        return MessengerResult::ok(new UpdatesBatch(
            messages:  $messages,
            cursor:    is_array($r->data['cursor'] ?? null) ? $r->data['cursor'] : [],
            baseline:  (bool) ($r->data['baseline'] ?? false),
            truncated: (bool) ($r->data['truncated'] ?? false),
            overflow:  array_map('strval', $r->data['overflow'] ?? []),
        ));
    }

    // ── AuthenticatesInteractivelyInterface ──────────────────────────────────

    public function beginAuth(): AuthStep
    {
        // A QR login left waiting (page reloaded, "start over") holds the
        // session lock — stop it, or status() would wait for the lock.
        $this->call(['auth:qr:cancel']);

        $status = $this->status();

        if ($status->authorized && $status->account !== null) {
            return AuthStep::done($status->account, 'Already logged in.');
        }

        $step = $this->phoneStep();

        // Missing credentials would only surface after the phone is submitted;
        // say it up front instead.
        return $status->code === MessengerResult::CODE_NOT_CONFIGURED
            ? $step->withError((string) $status->message)
            : $step;
    }

    public function submitAuth(string $step, array $input): AuthStep
    {
        return match ($step) {
            AuthStep::STEP_PHONE    => $this->submitPhone(trim((string) ($input['phone'] ?? ''))),
            AuthStep::STEP_CODE     => $this->submitCode(trim((string) ($input['code'] ?? ''))),
            AuthStep::STEP_PASSWORD => $this->submitPassword((string) ($input['password'] ?? '')),
            AuthStep::STEP_RESEND   => $this->resendCode(),
            AuthStep::STEP_QR       => !empty($input['check']) ? $this->qrStatus() : $this->qrStart(),
            default                 => $this->phoneStep()->withError("Unknown login step '{$step}'. Start again."),
        };
    }

    public function logout(): void
    {
        if ($this->presetId === null) {
            return;
        }

        $this->call(['auth:qr:cancel']);

        foreach (self::SESSION_FILES as $name) {
            $path = $this->dataDir() . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function submitPhone(string $phone): AuthStep
    {
        if ($phone === '') {
            return $this->phoneStep()->withError('Enter the phone number of the account.');
        }

        $r = $this->call(['auth:phone', $phone]);
        if (!$r->ok) {
            return $this->phoneStep()->withError((string) $r->error);
        }

        if (!empty($r->data['authorized'])) {
            return AuthStep::done($this->peerFromBrief($r->data['user'] ?? []), 'Already logged in.');
        }

        return $this->codeStep(is_array($r->data['code'] ?? null) ? $r->data['code'] : null);
    }

    /**
     * "The code never came": ask Telegram to deliver it the next way
     * (usually SMS or a call). Needs tgcli 1.2+.
     */
    private function resendCode(): AuthStep
    {
        $r = $this->call(['auth:resend']);

        if (!$r->ok) {
            return match ($r->code) {
                'no_auth_state' => $this->phoneStep()->withError('The login attempt expired. Request a new code.'),
                'usage'         => $this->codeStep()->withError(
                    'This tgcli version cannot resend codes — update tgcli to 1.2 or newer.'
                ),
                default         => $this->codeStep()->withError((string) $r->error),
            };
        }

        return $this->codeStep(is_array($r->data['code'] ?? null) ? $r->data['code'] : null, true);
    }

    private function submitCode(string $code): AuthStep
    {
        if ($code === '') {
            return $this->codeStep()->withError('Enter the code Telegram sent you.');
        }

        $r = $this->call(['auth:code', $code]);
        if (!$r->ok) {
            // Lost state between steps (e.g. tgcli auth state file removed) —
            // the only way forward is to request a new code.
            return $r->code === 'no_auth_state'
                ? $this->phoneStep()->withError('The login attempt expired. Request a new code.')
                : $this->codeStep()->withError((string) $r->error);
        }

        if (($r->data['next_step'] ?? null) === 'password') {
            return $this->passwordStep();
        }

        return AuthStep::done($this->peerFromBrief($r->data['user'] ?? []), 'Logged in.');
    }

    private function submitPassword(string $password): AuthStep
    {
        if ($password === '') {
            return $this->passwordStep()->withError('Enter the two-step verification password.');
        }

        $r = $this->call(['auth:password', $password]);
        if (!$r->ok) {
            return $this->passwordStep()->withError((string) $r->error);
        }

        return AuthStep::done($this->peerFromBrief($r->data['user'] ?? []), 'Logged in.');
    }

    private function phoneStep(): AuthStep
    {
        return new AuthStep(AuthStep::STEP_PHONE, [
            'phone' => [
                'type'        => 'text',
                'label'       => 'Phone number',
                'description' => 'International format. Telegram will send a login code to the account.',
                'placeholder' => '+380501234567',
                'required'    => true,
            ],
        ], 'Log in to the Telegram account this agent will use.', null, null, [], [
            ['step' => AuthStep::STEP_QR, 'label' => 'Log in with a QR code instead'],
        ]);
    }

    // ── QR login ─────────────────────────────────────────────────────────────
    //
    // Telegram may refuse to deliver login codes to third-party clients while
    // claiming they were "sent to the app". QR login needs no code: a phone
    // where the account is logged in scans the token. tgcli keeps a background
    // process waiting for the scan; the GUI polls auth:qr:status.

    /** Seconds between status polls the GUI should use. */
    private const QR_POLL = 2;

    private function qrStart(): AuthStep
    {
        // Starting includes connecting to Telegram (up to ~20s in tgcli).
        return $this->qrResult($this->call(['auth:qr'], 45));
    }

    private function qrStatus(): AuthStep
    {
        return $this->qrResult($this->call(['auth:qr:status']));
    }

    private function qrResult(TgcliResponse $r): AuthStep
    {
        if (!$r->ok) {
            return match ($r->code) {
                'qr_expired' => $this->phoneStep()->withError('The QR code expired. Start the QR login again.'),
                'usage'      => $this->phoneStep()->withError(
                    'This tgcli version has no QR login — update tgcli to 1.3 or newer.'
                ),
                'busy'       => $this->qrStep(null),   // still starting — keep polling
                default      => $this->phoneStep()->withError((string) $r->error),
            };
        }

        if (!empty($r->data['authorized'])) {
            return AuthStep::done($this->peerFromBrief($r->data['user'] ?? []), 'Logged in.');
        }

        if (($r->data['next_step'] ?? null) === 'password') {
            return $this->passwordStep();
        }

        $qr = is_array($r->data['qr'] ?? null) ? $r->data['qr'] : [];

        return $this->qrStep(isset($qr['url']) ? (string) $qr['url'] : null, (int) ($qr['expires'] ?? 0));
    }

    private function qrStep(?string $url, int $expires = 0): AuthStep
    {
        return new AuthStep(
            AuthStep::STEP_QR,
            [],
            'On a phone where this account is logged in, open Telegram → Settings → Devices → '
                . 'Link Desktop Device, and scan this code. It refreshes by itself.',
            null,
            null,
            array_filter([
                'qr'      => $url,
                'expires' => $expires ?: null,
                'poll'    => self::QR_POLL,
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * Where Telegram says it sent the code (tgcli 1.2+ reports it). Without
     * this, "the code never came" is a guessing game: since Telegram prefers
     * in-app delivery and login email, it is rarely an SMS.
     */
    private const CODE_DELIVERY = [
        'app'                  => 'in the Telegram app — a message from "Telegram" in a session where this account is logged in',
        'sms'                  => 'by SMS',
        'call'                 => 'by a phone call — the code will be dictated',
        'flash_call'           => 'by a flash call — the code is in the caller\'s number',
        'missed_call'          => 'by a missed call — the code is the last digits of the caller\'s number',
        'email'                => 'to the login email of the account',
        'fragment'             => 'via Fragment (fragment.com) — the number is anonymous',
        'setup_email_required' => 'nowhere yet: Telegram requires a login email for this account. Set it up in an official Telegram app (Settings → Privacy and Security → Login Email), then request the code again.',
    ];

    private const NEXT_DELIVERY = [
        'sms'         => 'by SMS',
        'call'        => 'by a call',
        'flash_call'  => 'by a flash call',
        'missed_call' => 'by a missed call',
        'fragment'    => 'via Fragment',
    ];

    /**
     * @param array<string, mixed>|null $info tgcli's `code` object: delivery,
     *                                        hint, length, email_pattern,
     *                                        next_delivery, resend_after
     */
    private function codeStep(?array $info = null, bool $resent = false): AuthStep
    {
        $delivery = (string) ($info['delivery'] ?? '');
        $where    = self::CODE_DELIVERY[$delivery] ?? (isset($info['hint']) ? (string) $info['hint'] : null);

        if ($where !== null && !empty($info['email_pattern'])) {
            $where .= ' (' . $info['email_pattern'] . ')';
        }

        $message = $where !== null
            ? ($resent ? 'Code sent again ' : 'Code sent ') . $where . '.'
            : ($resent ? 'Code sent again.' : 'Code sent.');

        $length = (int) ($info['length'] ?? 0);

        // Offer another delivery route unless Telegram says there is none.
        // Older tgcli reports nothing — offer it anyway; it will say if it can't.
        $actions = [];
        $next    = $info['next_delivery'] ?? null;
        if ($info === null || $next !== null) {
            $label = isset(self::NEXT_DELIVERY[$next])
                ? "Didn't get it? Send the code " . self::NEXT_DELIVERY[$next]
                : "Didn't get it? Send the code another way";
            $action = ['step' => AuthStep::STEP_RESEND, 'label' => $label];
            if (!empty($info['resend_after'])) {
                $action['wait'] = (int) $info['resend_after'];
            }
            $actions[] = $action;
        }

        $field = [
            'type'        => 'text',
            'label'       => 'Login code',
            'placeholder' => $length > 0 ? str_repeat('0', $length) : '12345',
            'required'    => true,
        ];
        if ($where === null) {
            $field['description'] = 'If the account is logged in anywhere, Telegram sends the code inside the app '
                . '(a message from "Telegram"), not by SMS. It may also go to the login email.';
        }

        return new AuthStep(AuthStep::STEP_CODE, [
            'code' => $field,
        ], $message, null, null, ['delivery' => $delivery !== '' ? $delivery : null], $actions);
    }

    private function passwordStep(): AuthStep
    {
        return new AuthStep(AuthStep::STEP_PASSWORD, [
            'password' => [
                'type'     => 'password',
                'label'    => 'Two-step verification password',
                'required' => true,
            ],
        ], 'This account has two-step verification enabled.');
    }

    /**
     * auth:* commands report a short user brief: {id, first_name, username}.
     */
    private function peerFromBrief(array $u): Peer
    {
        $username = isset($u['username']) && $u['username'] !== '' ? (string) $u['username'] : null;
        $name     = trim((string) ($u['first_name'] ?? ''));

        return new Peer(
            (string) ($u['id'] ?? ''),
            Peer::KIND_USER,
            $name !== '' ? $name : ($username ? '@' . $username : (string) ($u['id'] ?? '')),
            $username,
            true,
        );
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    public function dataDir(): string
    {
        return rtrim($this->baseDataDir, '/') . '/preset_' . $this->presetId;
    }

    /**
     * @param string[] $args
     */
    private function call(array $args, ?int $timeout = null): TgcliResponse
    {
        if ($this->presetId === null) {
            return TgcliResponse::failure('Messenger provider is not bound to a preset.', 'no_config');
        }

        $env = ['TELEGRAM_DATA_DIR' => $this->dataDir()];

        if ($this->apiId !== '' && $this->apiHash !== '') {
            $env['TELEGRAM_API_ID']   = $this->apiId;
            $env['TELEGRAM_API_HASH'] = $this->apiHash;
        }

        $env['TELEGRAM_DOWNLOAD_MAX_MB'] = (string) max(0, $this->downloadMaxMb);

        return $this->runner->call($args, $env, $timeout);
    }

    private function checkTarget(string $target): ?MessengerResult
    {
        $t = trim($target);

        if ($t === '') {
            return MessengerResult::fail('Target (@username or id) is required.', MessengerResult::CODE_INVALID);
        }
        // tgcli would read a leading dash as an option for some commands.
        if (str_starts_with($t, '--')) {
            return MessengerResult::fail("Invalid target: {$t}", MessengerResult::CODE_INVALID);
        }

        return null;
    }

    private function unbound(): MessengerResult
    {
        return MessengerResult::fail('Messenger provider is not bound to a preset.', MessengerResult::CODE_NOT_CONFIGURED);
    }

    private function ensureDataDir(): void
    {
        $dir = $this->dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
    }
}
