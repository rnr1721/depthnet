<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * Inbound policy for one preset: which incoming messages get into the agent's
 * context, and which of them wake it up.
 *
 * Built from the messenger capability config (see ProvidesInboundFields).
 * Pure logic, no I/O — the poller asks accepts()/wakes() per message.
 *
 * Modes:
 *   off        nothing comes in (default)
 *   whitelist  only listed chats/people
 *   all        everyone — anyone who can message the account can reach the
 *              agent. Messages are still marked as external input.
 *
 * Group/channel messages (independent of mode):
 *   ignore     dropped
 *   mentions   only messages that mention the account (or reply to it)
 *   all        all of them; only mentions wake the agent
 *
 * Whitelist entries are @usernames or ids. A private message passes when the
 * person is listed. A group message passes when the group OR its author is
 * listed — so a whitelisted friend can address the agent from a shared group.
 */
final class InboundSettings
{
    public const MODE_OFF       = 'off';
    public const MODE_WHITELIST = 'whitelist';
    public const MODE_ALL       = 'all';

    public const GROUPS_IGNORE   = 'ignore';
    public const GROUPS_MENTIONS = 'mentions';
    public const GROUPS_ALL      = 'all';

    /**
     * @param string[] $whitelist Normalized: ids as-is, usernames as lowercase "@name".
     */
    public function __construct(
        public readonly string $mode = self::MODE_OFF,
        public readonly array $whitelist = [],
        public readonly string $groups = self::GROUPS_IGNORE,
        public readonly bool $sendToPool = false,
    ) {
    }

    public static function off(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $config Messenger capability config.
     */
    public static function fromConfig(array $config): self
    {
        $mode = (string) ($config['inbound_mode'] ?? self::MODE_OFF);
        if (!in_array($mode, [self::MODE_OFF, self::MODE_WHITELIST, self::MODE_ALL], true)) {
            $mode = self::MODE_OFF;
        }

        $groups = (string) ($config['inbound_groups'] ?? self::GROUPS_IGNORE);
        if (!in_array($groups, [self::GROUPS_IGNORE, self::GROUPS_MENTIONS, self::GROUPS_ALL], true)) {
            $groups = self::GROUPS_IGNORE;
        }

        return new self(
            $mode,
            self::parseWhitelist($config['inbound_whitelist'] ?? ''),
            $groups,
            (bool) ($config['send_to_pool'] ?? false),
        );
    }

    /**
     * Accepts "@alena, 123456\n-1001820894363 alena_p" — commas, spaces or
     * newlines; "@" optional for usernames.
     *
     * @param  string|string[] $raw
     * @return string[]
     */
    public static function parseWhitelist(string|array $raw): array
    {
        $tokens = is_array($raw) ? $raw : (preg_split('/[\s,;]+/u', $raw) ?: []);
        $out    = [];

        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $out[] = preg_match('/^-?\d+$/', $t)
                ? $t
                : '@' . mb_strtolower(ltrim($t, '@'));
        }

        return array_values(array_unique($out));
    }

    public function isEnabled(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    /**
     * Whether this message should enter the agent's context at all.
     */
    public function accepts(InboundMessage $message): bool
    {
        if (!$this->isEnabled() || $message->outgoing || $message->author()->isSelf) {
            return false;
        }

        if (!$message->isPrivate()) {
            if ($this->groups === self::GROUPS_IGNORE) {
                return false;
            }
            if ($this->groups === self::GROUPS_MENTIONS && !$message->mentioned) {
                return false;
            }
        }

        if ($this->mode === self::MODE_ALL) {
            return true;
        }

        // Whitelist: the chat itself, or (in groups) the author.
        return $this->lists($message->chat)
            || ($message->sender !== null && $this->lists($message->sender));
    }

    /**
     * Whether this message should start a thinking cycle (be "a call to the
     * agent") rather than just sit in context until the next one.
     */
    public function wakes(InboundMessage $message): bool
    {
        return $this->accepts($message)
            && ($message->isPrivate() || $message->mentioned);
    }

    public function lists(Peer $peer): bool
    {
        if (in_array($peer->id, $this->whitelist, true)) {
            return true;
        }

        return $peer->username !== null && $peer->username !== ''
            && in_array('@' . mb_strtolower($peer->username), $this->whitelist, true);
    }
}
