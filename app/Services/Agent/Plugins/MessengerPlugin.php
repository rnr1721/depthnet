<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\Capabilities\DescribesChatsInterface;
use App\Contracts\Agent\Capabilities\ListsContactsInterface;
use App\Contracts\Agent\Capabilities\ListsDialogsInterface;
use App\Contracts\Agent\Capabilities\MarksReadInterface;
use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Contracts\Agent\Capabilities\MessengerServiceInterface;
use App\Contracts\Agent\Capabilities\SearchesMessagesInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;
use App\Services\Agent\Capabilities\Messenger\Inbound\MediaInterpreter;
use App\Services\Agent\Capabilities\Messenger\MessengerFormatter;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * MessengerPlugin — the agent's hands in whatever messenger the preset's
 * Messenger capability is configured with. Stateless; replaces TelegramPlugin.
 *
 * The plugin knows nothing about Telegram. It asks MessengerService for the
 * preset's provider and renders results with MessengerFormatter. An operation
 * the provider doesn't offer (no contacts in some messenger, say) fails with a
 * plain "not supported" error instead of disappearing from the schema — with
 * one provider today that implements everything, a dynamic schema would buy
 * nothing.
 *
 * Failures are thrown, not returned as text: CommandExecutor then reports the
 * command as ERROR, which is the signal the model actually reacts to.
 *
 * Config keys match TelegramPlugin's, so an existing preset's plugin config
 * row can be copied over as-is.
 */
class MessengerPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'messenger';

    private const ACCOUNT_CACHE_PREFIX = 'messenger_account_';
    private const UNREAD_CACHE_PREFIX  = 'messenger_unread_';

    /** Not-logged-in / not-configured states are cached briefly so a fresh login shows up fast. */
    private const FAILURE_CACHE_SECONDS = 60;

    /** Placeholder names. The legacy one keeps existing prompts working. */
    private const PLACEHOLDERS = ['messenger_account', 'telegram_account'];

    public function __construct(
        protected MessengerServiceInterface $messenger,
        protected MessengerFormatter $formatter,
        protected CacheRepository $cache,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected MediaInterpreter $media,
    ) {
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Messenger account (Telegram or another, as configured in Capabilities): '
            . 'read and send messages, browse dialogs, find people and chats, search.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'Find who to write to (people, groups, channels): [messenger resolve]Alena[/messenger]',
            'Read last messages: [messenger read]@username[/messenger]',
            'Read N messages: [messenger read]@username 30[/messenger]',
            'Send a message: [messenger send]@username Hello, how are you?[/messenger]',
            'Unread overview: [messenger unread][/messenger]',
            'List dialogs: [messenger dialogs][/messenger] or [messenger dialogs]50 groups[/messenger]',
            'Saved contacts: [messenger contacts][/messenger]',
            'Search in a chat: [messenger search]@groupname keyword[/messenger]',
            'Info about a user/group/channel: [messenger info]@username[/messenger]',
            'Mark a chat as read: [messenger mark_read]@username[/messenger]',
            'Hear a voice message or see a photo (#id from read): [messenger media]@username 4215[/messenger]',
            'Ask about a photo: [messenger media]@username 4215 what is the total on this receipt?[/messenger]',
            'Which account am I: [messenger me][/messenger]',
            'Addresses are exactly as printed by resolve/dialogs: @username or an id like -1001820894363.',
            $this->targetingGuidance($config),
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => self::PLUGIN_NAME,
            'description' => 'Your messenger account (configured in Capabilities — currently a real '
                . 'Telegram user account or another messenger). Read and send messages, browse dialogs, '
                . 'find people, groups and channels, search. Before sending to someone, use "resolve" '
                . 'to confirm the target exists and to get its exact address. '
                . $this->targetingGuidance($config),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => [
                            'resolve', 'read', 'send', 'unread', 'dialogs', 'contacts',
                            'search', 'info', 'mark_read', 'media', 'me',
                        ],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'resolve: name, @username or id to look up.',
                            'read: "@username" or "@username 30".',
                            'send: target, then the message text — "@username Hello!".',
                            'unread: optional limit, e.g. "10".',
                            'dialogs: optional "[limit] [users|groups|channels]", e.g. "50 groups".',
                            'contacts, me: leave empty.',
                            'search: chat target, then the query — "@groupname deposit".',
                            'info, mark_read: target.',
                            'media: chat target and message id (from read, e.g. #4215 → 4215), optionally a '
                                . 'question about an image — "@username 4215" or "@username 4215 what does it say?". '
                                . 'Returns a voice transcript or an image description.',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return 'Messenger: {method} done';
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Messenger: {method} failed';
    }

    // ── Placeholders ─────────────────────────────────────────────────────────

    /**
     * [[messenger_account]] (and legacy [[telegram_account]]): which account
     * the agent is, plus — optionally — a short unread overview as a passive
     * substitute for notifications.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $preset      = $context->preset;
        $presetId    = $preset->getId();
        $scope       = $this->shortcodeScopeResolver->preset($presetId);
        $accountTtl  = max(1, (int) $context->get('account_cache_minutes', 10)) * 60;
        $showUnread  = (bool) $context->get('show_unread', false);
        $unreadCount = max(1, (int) $context->get('unread_count', 5));
        $unreadTtl   = max(1, (int) $context->get('unread_cache_minutes', 3)) * 60;

        $resolver = function () use ($preset, $presetId, $accountTtl, $showUnread, $unreadCount, $unreadTtl) {
            $account = $this->cachedLine(
                self::ACCOUNT_CACHE_PREFIX . $presetId,
                $accountTtl,
                fn () => $this->accountLine($preset),
            );

            if (!$showUnread) {
                return $account;
            }

            $unread = $this->cachedLine(
                self::UNREAD_CACHE_PREFIX . $presetId,
                $unreadTtl,
                fn () => $this->unreadLine($preset, $unreadCount),
            );

            return $unread === '' ? $account : $account . "\n\n" . $unread;
        };

        foreach (self::PLACEHOLDERS as $name) {
            $this->placeholderService->registerDynamic(
                $name,
                'Current messenger account, plus recent unread messages if enabled',
                $resolver,
                $scope,
                false,
                $this->getName()
            );
        }
    }

    // ── Commands ─────────────────────────────────────────────────────────────

    /**
     * Bare [messenger][/messenger]: no free-form passthrough any more (the old
     * raw tgcli mode was a shell-injection path). Point at the real commands.
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        throw new \InvalidArgumentException(
            'Specify a method, e.g. [messenger unread][/messenger] or [messenger send]@username text[/messenger]. '
            . 'Available: resolve, read, send, unread, dialogs, contacts, search, info, mark_read, media, me.'
        );
    }

    public function resolve(string $content, PluginExecutionContext $context): string
    {
        $query = trim($content);
        if ($query === '') {
            throw new \InvalidArgumentException('Provide a name, @username or id to look up.');
        }

        $peers = $this->unwrap($this->provider($context)->resolve($query));

        return $this->formatter->resolveResult($query, $peers);
    }

    public function read(string $content, PluginExecutionContext $context): string
    {
        [$target, $rest] = $this->splitTarget($content);
        $limit = ctype_digit($rest) ? (int) $rest : (int) $context->get('default_read_limit', 15);

        $history = $this->unwrap($this->provider($context)->read($target, $limit));

        return $this->formatter->history($history);
    }

    public function send(string $content, PluginExecutionContext $context): string
    {
        [$target, $text] = $this->splitTarget($content);
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Message text is required: [messenger send]@username text[/messenger]');
        }

        $sent = $this->unwrap($this->provider($context)->send($target, $text));

        return $this->formatter->sent($sent);
    }

    public function unread(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->capable($context, ListsDialogsInterface::class, 'unread');
        $limit    = ctype_digit(trim($content)) ? (int) trim($content) : 20;

        return $this->formatter->dialogs($this->unwrap($provider->unread($limit)), 'Unread');
    }

    public function dialogs(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->capable($context, ListsDialogsInterface::class, 'dialogs');

        $parts = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $limit = isset($parts[0]) && ctype_digit($parts[0]) ? (int) array_shift($parts) : 30;
        $kind  = $parts[0] ?? null;

        return $this->formatter->dialogs($this->unwrap($provider->dialogs($limit, $kind)));
    }

    public function contacts(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->capable($context, ListsContactsInterface::class, 'contacts');

        return $this->formatter->contacts($this->unwrap($provider->contacts()));
    }

    public function search(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->capable($context, SearchesMessagesInterface::class, 'search');

        [$target, $query] = $this->splitTarget($content);
        if (trim($query) === '') {
            throw new \InvalidArgumentException('Search query is required: [messenger search]@chat keyword[/messenger]');
        }

        return $this->formatter->history($this->unwrap($provider->search($target, $query)), 'search');
    }

    public function info(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->capable($context, DescribesChatsInterface::class, 'info');
        [$target] = $this->splitTarget($content);

        return $this->formatter->info($this->unwrap($provider->info($target)));
    }

    /**
     * snake_case on purpose: the tool schema and existing prompts say "mark_read".
     */
    public function mark_read(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->capable($context, MarksReadInterface::class, 'mark_read');
        [$target] = $this->splitTarget($content);

        $chat = $this->unwrap($provider->markRead($target));

        return 'Marked as read: ' . $this->formatter->peer($chat);
    }

    /**
     * Voice → transcript, photo → description, via the preset's STT / Vision
     * capabilities. "@chat 4215" or "@chat #4215 question about the image".
     */
    public function media(string $content, PluginExecutionContext $context): string
    {
        [$target, $rest] = $this->splitTarget($content);
        $parts     = preg_split('/\s+/u', $rest, 2) ?: [];
        $messageId = ltrim((string) ($parts[0] ?? ''), '#');
        $question  = trim((string) ($parts[1] ?? '')) ?: null;

        if (!ctype_digit($messageId)) {
            throw new \InvalidArgumentException(
                'Give the chat and the message id: [messenger media]@username 4215[/messenger] '
                . '(ids are the #numbers shown by read).'
            );
        }

        $result = $this->media->interpret(
            $context->preset,
            $this->provider($context),
            $target,
            $messageId,
            null,
            $question,
        );

        if ($result === null || !$result->succeeded()) {
            throw new \RuntimeException('Message #' . $messageId . ': ' . ($result?->text ?? 'nothing to interpret'));
        }

        return trim("Message #{$messageId} " . ($result->media?->label() ?? '') . ' ' . $result->render());
    }

    public function me(string $content, PluginExecutionContext $context): string
    {
        $provider = $this->provider($context);
        $status   = $provider->status();

        if (!$status->authorized) {
            throw new \RuntimeException($this->notReadyMessage($status->message, $status->code));
        }

        $line = $this->formatter->account($status, $provider->getDisplayName());

        // Refresh the placeholder cache while we're at it.
        $this->cache->put(
            self::ACCOUNT_CACHE_PREFIX . $context->preset->getId(),
            $line,
            max(1, (int) $context->get('account_cache_minutes', 10)) * 60,
        );

        return $line;
    }

    // ── Config ───────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Messenger Plugin',
                'description' => 'Lets the agent use the messenger account configured in Capabilities → Messenger.',
                'required'    => false,
            ],
            'default_read_limit' => [
                'type'        => 'number',
                'label'       => 'Default read limit',
                'description' => 'How many messages "read" fetches when no count is given',
                'min'         => 1,
                'max'         => 100,
                'value'       => 15,
                'required'    => false,
            ],
            'account_cache_minutes' => [
                'type'        => 'number',
                'label'       => 'Account cache (minutes)',
                'description' => 'How long [[messenger_account]] caches the account line',
                'min'         => 1,
                'max'         => 1440,
                'value'       => 10,
                'required'    => false,
            ],
            'targeting_guidance' => [
                'type'        => 'textarea',
                'label'       => 'Targeting guidance',
                'description' => 'How the agent should decide whom to write to and handle ambiguity. '
                    . 'Neutral by default (verify before sending). Override for stricter behavior, '
                    . 'e.g. "if unsure, always ask the user which contact you mean".',
                'value'       => $this->targetingGuidance(),
                'required'    => false,
            ],
            'show_unread' => [
                'type'        => 'checkbox',
                'label'       => 'Show unread in account placeholder',
                'description' => 'Add a short unread overview to [[messenger_account]]. For real delivery '
                    . 'of incoming messages, use Incoming messages in Capabilities → Messenger instead.',
                'value'       => false,
                'required'    => false,
            ],
            'unread_count' => [
                'type'        => 'number',
                'label'       => 'Unread chats to show',
                'min'         => 1,
                'max'         => 20,
                'value'       => 5,
                'required'    => false,
            ],
            'unread_cache_minutes' => [
                'type'        => 'number',
                'label'       => 'Unread cache (minutes)',
                'min'         => 1,
                'max'         => 60,
                'value'       => 3,
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'               => false,
            'default_read_limit'    => 15,
            'account_cache_minutes' => 10,
            'targeting_guidance'    => $this->targetingGuidance(),
            'show_unread'           => false,
            'unread_count'          => 5,
            'unread_cache_minutes'  => 3,
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        foreach ([
            'default_read_limit'    => [1, 100],
            'account_cache_minutes' => [1, 1440],
            'unread_count'          => [1, 20],
            'unread_cache_minutes'  => [1, 60],
        ] as $key => [$min, $max]) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $v = (int) $config[$key];
                if ($v < $min || $v > $max) {
                    $errors[$key] = "Must be between {$min} and {$max}.";
                }
            }
        }

        return $errors;
    }

    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['unread', 'me', 'contacts', 'dialogs'];
    }

    public function needsLongContext(): bool
    {
        return true;
    }

    // ── Internals (private — never exposed as commands) ──────────────────────

    private function provider(PluginExecutionContext $context): MessengerProviderInterface
    {
        $provider = $this->messenger->provider($context->preset);

        if ($provider === null) {
            throw new \RuntimeException(
                'No messenger is configured for this agent. The operator sets it up in '
                . 'Capabilities → Messenger (and logs in there).'
            );
        }

        return $provider;
    }

    /**
     * Provider that implements the optional interface an operation needs.
     *
     * @template T
     * @param  class-string<T> $interface
     * @return T&MessengerProviderInterface
     */
    private function capable(PluginExecutionContext $context, string $interface, string $operation): MessengerProviderInterface
    {
        $provider = $this->provider($context);

        if (!$provider instanceof $interface) {
            throw new \RuntimeException(
                "\"{$operation}\" is not supported by {$provider->getDisplayName()}."
            );
        }

        return $provider;
    }

    private function unwrap(MessengerResult $result): mixed
    {
        if ($result->success) {
            return $result->value;
        }

        $message = (string) $result->error;

        $message .= match ($result->code) {
            MessengerResult::CODE_RATE_LIMITED => $result->retryAfter
                ? " Wait {$result->retryAfter}s before trying again."
                : ' Wait before trying again.',
            MessengerResult::CODE_BUSY        => ' The account is busy right now; try again shortly.',
            MessengerResult::CODE_NOT_FOUND   => ' Use resolve to find the exact address.',
            MessengerResult::CODE_NOT_AUTHORIZED,
            MessengerResult::CODE_NOT_CONFIGURED => ' ' . $this->notReadyMessage(null, $result->code),
            default => '',
        };

        throw new \RuntimeException(trim($message));
    }

    private function notReadyMessage(?string $detail, ?string $code): string
    {
        $hint = $code === MessengerResult::CODE_NOT_CONFIGURED
            ? 'The messenger is not fully configured — the operator fixes it in Capabilities → Messenger.'
            : 'The messenger account is not logged in — the operator logs in under Capabilities → Messenger.';

        return $detail ? "{$detail} {$hint}" : $hint;
    }

    /**
     * "@user rest of the text" → ['@user', 'rest of the text'].
     * Outer whitespace of the text is trimmed; inner newlines are kept.
     *
     * @return array{0: string, 1: string}
     */
    private function splitTarget(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Target (@username or id) is required.');
        }

        $parts  = preg_split('/\s+/u', $content, 2) ?: [$content];
        $target = $parts[0];
        $rest   = $parts[1] ?? '';

        return [$target, trim($rest)];
    }

    private function accountLine(AiPreset $preset): array
    {
        $provider = $this->messenger->provider($preset);
        if ($provider === null) {
            return ['Messenger: not configured.', false];
        }

        $status = $provider->status();

        return [$this->formatter->account($status, $provider->getDisplayName()), $status->authorized];
    }

    private function unreadLine(AiPreset $preset, int $count): array
    {
        $provider = $this->messenger->provider($preset);
        if (!$provider instanceof ListsDialogsInterface) {
            return ['', true];
        }

        $result = $provider->unread($count);
        if (!$result->success) {
            return ['', false];
        }

        return [$this->formatter->dialogs($result->value, 'Unread'), true];
    }

    /**
     * Cache a placeholder line: full TTL when the lookup succeeded, a short one
     * when it didn't — so logging in shows up within a minute, not an hour.
     *
     * @param callable(): array{0: string, 1: bool} $produce
     */
    private function cachedLine(string $key, int $ttlSeconds, callable $produce): string
    {
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            [$line, $ok] = $produce();
        } catch (\Throwable) {
            [$line, $ok] = ['Messenger: unavailable right now.', false];
        }

        $this->cache->put($key, $line, $ok ? $ttlSeconds : self::FAILURE_CACHE_SECONDS);

        return $line;
    }

    private function targetingGuidance(array $config = []): string
    {
        if (!empty($config['targeting_guidance'])) {
            return (string) $config['targeting_guidance'];
        }

        return 'You may know who to contact from any context — memory, documents, the conversation, '
            . 'the task. But before sending, verify the target exists: [messenger resolve]<name or @username>[/messenger]. '
            . 'One match — use it. Several — pick using your context. Zero — the target does not exist '
            . 'here; report that instead of guessing an address.';
    }
}
