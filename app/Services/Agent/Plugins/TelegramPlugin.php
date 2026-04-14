<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Integrations\Telegram\TelegramServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * TelegramPlugin class
 *
 * Provides access to Telegram via TelegramServiceInterface.
 * All tgcli interaction is delegated to the service.
 *
 * Registers [[telegram_account]] placeholder so the agent always knows
 * which account it is authorized under.
 *
 * Supported tags:
 *   [telegram]<raw command>[/telegram]          — pass any command directly to tgcli
 *   [telegram dialogs]50 channels[/telegram]    — list dialogs
 *   [telegram read]@username 20[/telegram]      — read messages
 *   [telegram send]@username Text[/telegram]    — send a message
 *   [telegram unread][/telegram]                — unread dialogs
 *   [telegram search]@chat keyword[/telegram]   — search in chat
 *   [telegram info]@username[/telegram]         — info about user or channel
 *   [telegram mark_read]@username[/telegram]    — mark dialog as read
 *   [telegram me][/telegram]                    — current account info
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
        $this->initializeConfig();
    }

    // -- Identity -------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'telegram';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Access Telegram: read and send messages, browse dialogs and channels, search, get user info.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
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

    /**
     * Tool schema for tool_calls mode.
     *
     * @return array OpenAI-compatible function descriptor (inner "function" object)
     */
    public function getToolSchema(): array
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
                            'dialogs',    // list dialogs
                            'read',       // read messages from a dialog
                            'send',       // send a message
                            'unread',     // show unread dialogs
                            'search',     // search in a chat
                            'info',       // user or channel info
                            'mark_read',  // mark dialog as read
                            'me',         // current account info
                            'execute',    // raw tgcli command (fallback)
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

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return 'Telegram command executed. Method: {method}';
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return 'Telegram command failed. Method: {method}';
    }

    // -- Placeholder registration ---------------------------------------------

    /**
     * Register [[telegram_account]] placeholder so the agent always knows
     * which Telegram account it is authorized under.
     * Account info is cached to avoid hitting tgcli on every cycle.
     *
     * @inheritDoc
     */
    public function pluginReady(AiPreset $preset): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $presetId = $preset->getId();
        $scope    = $this->shortcodeScopeResolver->preset($presetId);
        $cacheMins = (int) ($this->config['account_cache_minutes'] ?? 60);
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

    // -- Default execute ------------------------------------------------------

    /**
     * Default execute — passes content as a raw tgcli command.
     *
     * @inheritDoc
     */
    public function execute(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return '[ERROR] Telegram plugin is disabled.';
        }

        $content = trim($content);
        if ($content === '') {
            return '[ERROR] No command provided.';
        }

        return $this->telegram->run($preset->getId(), $content);
    }

    // -- Methods --------------------------------------------------------------

    /**
     * List dialogs.
     * Content: [limit] [users|groups|channels]
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function dialogs(string $content, AiPreset $preset): string
    {
        $parts = preg_split('/\s+/', trim($content));
        $limit = isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : 30;
        $kind  = $parts[1] ?? null;

        return $this->telegram->dialogs($preset->getId(), $limit, $kind);
    }

    /**
     * Read messages from a dialog / group / channel.
     * Content: <@chat|id> [limit]
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function read(string $content, AiPreset $preset): string
    {
        $parts  = preg_split('/\s+/', trim($content), 2);
        $target = $parts[0] ?? '';
        $limit  = isset($parts[1]) && is_numeric($parts[1])
            ? (int) $parts[1]
            : ($this->config['default_read_limit'] ?? 15);

        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }

        return $this->telegram->read($preset->getId(), $target, $limit);
    }

    /**
     * Send a message.
     * Content: <@user|id> <text...>
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function send(string $content, AiPreset $preset): string
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

        return $this->telegram->send($preset->getId(), $target, $text);
    }

    /**
     * Show dialogs with unread messages.
     * Content: [limit]
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function unread(string $content, AiPreset $preset): string
    {
        $limit = trim($content);
        return $this->telegram->unread(
            $preset->getId(),
            ($limit !== '' && is_numeric($limit)) ? (int) $limit : 20
        );
    }

    /**
     * Search messages in a chat.
     * Content: <@chat|id> <query...>
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function search(string $content, AiPreset $preset): string
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

        return $this->telegram->search($preset->getId(), $target, $query);
    }

    /**
     * Get info about a user or channel.
     * Content: <@user|channel|id>
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function info(string $content, AiPreset $preset): string
    {
        $target = trim($content);
        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }
        return $this->telegram->info($preset->getId(), $target);
    }

    /**
     * Mark a dialog as read.
     * Content: <@chat|id>
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function mark_read(string $content, AiPreset $preset): string
    {
        $target = trim($content);
        if ($target === '') {
            return '[ERROR] Target (@username or id) is required.';
        }
        return $this->telegram->markRead($preset->getId(), $target);
    }

    /**
     * Show current account info.
     * Bypasses cache — always fresh.
     *
     * @param string   $content
     * @param AiPreset $preset
     * @return string
     */
    public function me(string $content, AiPreset $preset): string
    {
        $output = $this->telegram->me($preset->getId());

        // Refresh cache after explicit me() call
        $this->cache->put(
            self::ACCOUNT_CACHE_PREFIX . $preset->getId(),
            'Your current Telegram account:' . "\n" . trim($output),
            now()->addMinutes((int) ($this->config['account_cache_minutes'] ?? 60))
        );

        return $output;
    }

    // -- Config ---------------------------------------------------------------

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled'               => true,
            'default_read_limit'    => 15,
            'account_cache_minutes' => 60,
        ];
    }

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        // Clear cache before test so we get a fresh result
        // presetId unknown here — just return true, real test happens via status endpoint
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['unread', 'me'];
    }
}
