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
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'telegram',
            'description' => 'Access Telegram via a real user account (MTProto). '
                . 'Read and send messages, browse dialogs, channels and groups, search, get user info.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Telegram operation to perform',
                        'enum'        => [
                            'dialogs', 'read', 'send', 'unread', 'search',
                            'info', 'mark_read', 'me', 'execute',
                        ],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method.',
                            'dialogs: optional "[limit] [users|groups|channels]", e.g. "50 channels" or leave empty.',
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

        $this->placeholderService->registerDynamic(
            'telegram_account',
            'Current Telegram account (username, name, ID)',
            function () use ($presetId, $cacheKey, $cacheMins) {
                return $this->cache->remember(
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
            },
            $scope
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
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'               => false,
            'default_read_limit'    => 15,
            'account_cache_minutes' => 60,
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
        return ['unread', 'me'];
    }
}
