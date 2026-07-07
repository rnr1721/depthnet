<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Integrations\Telegram\TelegramServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * TelegramPlugin — stateless.
 *
 * Provides access to Telegram via TelegramServiceInterface. All tgcli
 * interaction is delegated to the service. Registers [[telegram_account]]
 * placeholder so the agent always knows which account it is authorized under.
 */
class TelegramPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    private const ACCOUNT_CACHE_PREFIX = 'telegram_account_';
    private const UNREAD_CACHE_PREFIX  = 'telegram_unread_';

    public function __construct(
        protected TelegramServiceInterface               $telegram,
        protected CacheRepository                        $cache,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram';
    }

    public function getDescription(array $config = []): string
    {
        return 'Access Telegram: read and send messages, browse dialogs and channels, search, get user info.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'List all dialogs: [telegram dialogs][/telegram]',
            'List channels only: [telegram dialogs]50 channels[/telegram]',
            'List groups only: [telegram dialogs]50 groups[/telegram]',
            'List saved contacts: [telegram contacts][/telegram]',
            'Find who to write to (people, groups, channels): [telegram resolve]Алёна[/telegram]',
            'Resolve verifies a target exists before you send: [telegram resolve]@username[/telegram]',
            'Read last messages: [telegram read]@username[/telegram]',
            'Read N messages: [telegram read]@username 30[/telegram]',
            'Read by numeric id: [telegram read]1820894363 10[/telegram]',
            'Send a message: [telegram send]@username Hello, how are you?[/telegram]',
            'Check unread: [telegram unread][/telegram]',
            'Search in chat: [telegram search]@groupname some keyword[/telegram]',
            'Get user/channel info: [telegram info]@username[/telegram]',
            'Mark as read: [telegram mark_read]@username[/telegram]',
            'My account info: [telegram me][/telegram]',
            'Raw command (fallback): [telegram]dialogs 20 users[/telegram]',
            $this->getTargetingGuidance($config),
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'telegram',
            'description' => 'Access Telegram via a real user account (MTProto). '
                . 'Read and send messages, browse dialogs, channels and groups, search, get user info. '
                . 'You may determine who to contact from any context available to you; the tool does '
                . 'not restrict the source. Before sending to someone, use "resolve" to confirm the '
                . 'target exists in this account and to obtain its canonical address (@username or id) — '
                . 'this is how you avoid writing to a target that does not exist. "contacts" lists the '
                . 'address book; "resolve" searches contacts and all dialogs (people, groups, channels).',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Telegram operation to perform',
                        'enum'        => [
                            'dialogs', 'contacts', 'resolve', 'read', 'send',
                            'unread', 'search', 'info', 'mark_read', 'me', 'execute',
                        ],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'dialogs: optional "[limit] [users|groups|channels]", e.g. "50 channels" or leave empty.',
                            'contacts: leave empty — lists the saved address book.',
                            'resolve: "<name, @username or id>" — finds real targets across contacts '
                                . 'and dialogs, prints candidates with an explicit match count.',
                            'read: "@username" or "@username 30" — target and optional message count.',
                            'send: "@username message text" — target followed by the message, e.g. "@Eugeny Hello!".',
                            'unread: optional limit, e.g. "20" or leave empty.',
                            'search: "@chat keyword" — chat target followed by search query, e.g. "@groupname депозит".',
                            'info: "@username" or numeric id.',
                            'mark_read: "@username" or numeric id.',
                            'me: leave empty.',
                            'execute: raw tgcli command string (fallback for unsupported operations).',
                        ]),
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return 'Telegram command executed. Method: {method}';
    }

    public function getCustomErrorMessage(): ?string
    {
        return 'Telegram command failed. Method: {method}';
    }

    /**
     * Register [[telegram_account]] placeholder.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $presetId  = $context->preset->getId();
        $scope     = $this->shortcodeScopeResolver->preset($presetId);
        $cacheMins = (int) $context->get('account_cache_minutes', 60);
        $cacheKey  = self::ACCOUNT_CACHE_PREFIX . $presetId;

        // Unread config — read once here so the closure captures it.
        $showUnread   = (bool) $context->get('show_unread', true);
        $unreadCount  = (int) $context->get('unread_count', 5);
        $unreadTtl    = (int) $context->get('unread_cache_minutes', 3);
        $unreadKey    = self::UNREAD_CACHE_PREFIX . $presetId;

        $this->placeholderService->registerDynamic(
            'telegram_account',
            'Current Telegram account, plus recent unread messages',
            function () use (
                $presetId,
                $cacheKey,
                $cacheMins,
                $showUnread,
                $unreadCount,
                $unreadTtl,
                $unreadKey
            ) {
                // -- Account (long TTL) --
                $account = $this->cache->remember(
                    $cacheKey,
                    now()->addMinutes($cacheMins),
                    function () use ($presetId) {
                        $status = $this->telegram->getStatus($presetId);
                        if (!$status['authorized']) {
                            return 'Telegram: not authorized.';
                        }
                        return 'My current Telegram account:' . "\n" . trim($status['output']);
                    }
                );

                if (!$showUnread) {
                    return $account;
                }

                // -- Unread (short TTL, independent) --
                $unread = $this->cache->remember(
                    $unreadKey,
                    now()->addMinutes($unreadTtl),
                    fn () => trim($this->telegram->unread($presetId, $unreadCount))
                );

                return $account . "\n\n" . 'Recent unread:' . "\n" . $unread;
            },
            $scope,
            false,
            $this->getName()
        );
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return '[ERROR] Telegram plugin is disabled.';
        }

        $content = trim($content);
        if ($content === '') {
            return '[ERROR] No command provided.';
        }

        return $this->telegram->run($context->preset->getId(), $content);
    }

    public function dialogs(string $content, PluginExecutionContext $context): string
    {
        $parts = preg_split('/\s+/', trim($content));
        $limit = isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : 30;
        $kind  = $parts[1] ?? null;

        return $this->telegram->dialogs($context->preset->getId(), $limit, $kind);
    }

    public function read(string $content, PluginExecutionContext $context): string
    {
        $parts  = preg_split('/\s+/', trim($content), 2);
        $target = $parts[0] ?? '';
        $limit  = isset($parts[1]) && is_numeric($parts[1])
            ? (int) $parts[1]
            : (int) $context->get('default_read_limit', 15);

        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }

        return $this->telegram->read($context->preset->getId(), $target, $limit);
    }

    public function send(string $content, PluginExecutionContext $context): string
    {
        $parts  = preg_split('/\s+/', trim($content), 2);
        $target = $parts[0] ?? '';
        $text   = $parts[1] ?? '';

        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }
        if ($text === '') {
            return '[ERROR] Message text is required.';
        }

        return $this->telegram->send($context->preset->getId(), $target, $text);
    }

    public function unread(string $content, PluginExecutionContext $context): string
    {
        $limit = trim($content);
        return $this->telegram->unread(
            $context->preset->getId(),
            ($limit !== '' && is_numeric($limit)) ? (int) $limit : 20
        );
    }

    public function search(string $content, PluginExecutionContext $context): string
    {
        $parts  = preg_split('/\s+/', trim($content), 2);
        $target = $parts[0] ?? '';
        $query  = $parts[1] ?? '';

        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }
        if ($query === '') {
            return '[ERROR] Search query is required.';
        }

        return $this->telegram->search($context->preset->getId(), $target, $query);
    }

    public function info(string $content, PluginExecutionContext $context): string
    {
        $target = trim($content);
        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }
        return $this->telegram->info($context->preset->getId(), $target);
    }

    public function mark_read(string $content, PluginExecutionContext $context): string
    {
        $target = trim($content);
        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }
        return $this->telegram->markRead($context->preset->getId(), $target);
    }

    public function me(string $content, PluginExecutionContext $context): string
    {
        $output = $this->telegram->me($context->preset->getId());

        $this->cache->put(
            self::ACCOUNT_CACHE_PREFIX . $context->preset->getId(),
            'Your current Telegram account:' . "\n" . trim($output),
            now()->addMinutes((int) $context->get('account_cache_minutes', 60))
        );

        return $output;
    }

    public function contacts(string $content, PluginExecutionContext $context): string
    {
        return $this->telegram->contacts($context->preset->getId());
    }

    public function resolve(string $content, PluginExecutionContext $context): string
    {
        $query = trim($content);
        if ($query === '') {
            return '[ERROR] Query is required. Provide a name, @username or id to look up.';
        }
        return $this->telegram->resolve($context->preset->getId(), $query);
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Telegram Plugin',
                'description' => 'Allow interaction with Telegram via tgcli',
                'required'    => false,
            ],
            'default_read_limit' => [
                'type'        => 'number',
                'label'       => 'Default read limit',
                'description' => 'How many messages to fetch when limit is not specified',
                'min'         => 1,
                'max'         => 100,
                'value'       => 15,
                'required'    => false,
            ],
            'account_cache_minutes' => [
                'type'        => 'number',
                'label'       => 'Account cache (minutes)',
                'description' => 'How long to cache Telegram account info for [[telegram_account]] placeholder',
                'min'         => 5,
                'max'         => 1440,
                'value'       => 60,
                'required'    => false,
            ],
            'targeting_guidance' => [
                'type'        => 'textarea',
                'label'       => 'Targeting guidance',
                'description' => 'How the agent should decide whom to write to and handle ambiguity. '
                    . 'Neutral by default (verify before send, resolve ambiguity by any means). '
                    . 'Override with your own rules if you want stricter behavior, '
                    . 'e.g. "if unsure, always ask the user which contact you mean".',
                'value'       => $this->getTargetingGuidance(),
                'required'    => false,
            ],
            'show_unread' => [
                'type'        => 'checkbox',
                'label'       => 'Show unread in account placeholder',
                'description' => 'Inject recent unread messages into [[telegram_account]] so the agent '
                    . 'passively sees who wrote (with their address) — a substitute for push notifications',
                'value'       => false,
                'required'    => false,
            ],
            'unread_count' => [
                'type'        => 'number',
                'label'       => 'Unread messages to show',
                'description' => 'How many recent unread dialogs to inject into the placeholder',
                'min'         => 1,
                'max'         => 20,
                'value'       => 5,
                'required'    => false,
            ],
            'unread_cache_minutes' => [
                'type'        => 'number',
                'label'       => 'Unread cache (minutes)',
                'description' => 'How long to cache the unread summary. Keep short — this is a live feed, '
                    . 'not static info like the account name',
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
            'account_cache_minutes' => 60,
            'targeting_guidance'    => $this->getTargetingGuidance(),
            'show_unread'           => false,
            'unread_cache_minutes'  => 3,
            'unread_count'          => 5,
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['default_read_limit'])) {
            $l = (int) $config['default_read_limit'];
            if ($l < 1 || $l > 100) {
                $errors['default_read_limit'] = 'Default read limit must be between 1 and 100.';
            }
        }

        if (isset($config['account_cache_minutes'])) {
            $m = (int) $config['account_cache_minutes'];
            if ($m < 5 || $m > 1440) {
                $errors['account_cache_minutes'] = 'Cache duration must be between 5 and 1440 minutes.';
            }
        }

        if (isset($config['unread_count'])) {
            $c = (int) $config['unread_count'];
            if ($c < 1 || $c > 20) {
                $errors['unread_count'] = 'Unread count must be between 1 and 20.';
            }
        }

        if (isset($config['unread_cache_minutes'])) {
            $u = (int) $config['unread_cache_minutes'];
            if ($u < 1 || $u > 60) {
                $errors['unread_cache_minutes'] = 'Unread cache must be between 1 and 60 minutes.';
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
        return ['unread', 'me', 'contacts'];
    }

    /**
     * If there are a lot of actions in Telegram, then a lot of context may be required.
     */
    public function needsLongContext(): bool
    {
        return true;
    }

    private function getTargetingGuidance(array $config = []): string
    {
        if (!empty($config['targeting_guidance'])) {
            return $config['targeting_guidance'];
        }

        return 'You may know who to contact from any context — memory, documents, '
            . 'the conversation, the task. But before sending, verify the target exists: '
            . 'run [telegram resolve]<name or @username>[/telegram]. '
            . 'One match — use it. Several — pick using your context. '
            . 'Zero — the target does not exist here; report that (the request may rest on '
            . 'a wrong assumption) instead of guessing an address.';
    }

}
