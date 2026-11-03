<?php

namespace App\Services\Agent\Capabilities\Messenger\Drivers\Telegram;

use App\Services\Agent\Capabilities\Messenger\DTO\Dialog;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundMessage;
use App\Services\Agent\Capabilities\Messenger\DTO\MediaInfo;
use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;
use App\Services\Agent\Capabilities\Messenger\DTO\Peer;
use DateTimeImmutable;

/**
 * Maps tgcli 1.1 JSON shapes to the provider-agnostic DTOs.
 *
 * Kept separate from the provider so the JSON contract with tgcli lives in one
 * place — when tgcli's output changes, this is the only file to touch.
 */
final class TgcliMapper
{
    /** tgcli error code → MessengerResult code. */
    private const ERROR_CODES = [
        'no_config'       => MessengerResult::CODE_NOT_CONFIGURED,
        'bad_config'      => MessengerResult::CODE_NOT_CONFIGURED,
        'no_session'      => MessengerResult::CODE_NOT_AUTHORIZED,
        'not_authorized'  => MessengerResult::CODE_NOT_AUTHORIZED,
        'no_auth_state'   => MessengerResult::CODE_NOT_AUTHORIZED,
        'not_found'       => MessengerResult::CODE_NOT_FOUND,
        'flood_wait'      => MessengerResult::CODE_RATE_LIMITED,
        'session_busy'    => MessengerResult::CODE_BUSY,
        'usage'           => MessengerResult::CODE_INVALID,
        'bad_state'       => MessengerResult::CODE_INVALID,
        'no_media'        => MessengerResult::CODE_INVALID,
        'too_large'       => MessengerResult::CODE_INVALID,
        'download_failed' => MessengerResult::CODE_ERROR,
        'timeout'         => MessengerResult::CODE_UNAVAILABLE,
        'process'         => MessengerResult::CODE_UNAVAILABLE,
    ];

    public static function errorCode(?string $tgcliCode): string
    {
        return self::ERROR_CODES[$tgcliCode] ?? MessengerResult::CODE_ERROR;
    }

    public static function failure(TgcliResponse $r): MessengerResult
    {
        return MessengerResult::fail(
            (string) $r->error,
            self::errorCode($r->code),
            $r->retryAfter,
        );
    }

    /**
     * tgcli entity dict: {peer_id, id, kind, name, username, self?}
     */
    public static function peer(array $e): Peer
    {
        $kind = (string) ($e['kind'] ?? Peer::KIND_OTHER);
        if (!in_array($kind, [Peer::KIND_USER, Peer::KIND_BOT, Peer::KIND_GROUP, Peer::KIND_CHANNEL], true)) {
            $kind = Peer::KIND_OTHER;
        }

        return new Peer(
            id:       (string) ($e['peer_id'] ?? $e['id'] ?? ''),
            kind:     $kind,
            name:     (string) ($e['name'] ?? ''),
            username: isset($e['username']) && $e['username'] !== '' ? (string) $e['username'] : null,
            isSelf:   (bool) ($e['self'] ?? false),
        );
    }

    /**
     * @param  array[] $list
     * @return Peer[]
     */
    public static function peers(array $list): array
    {
        return array_values(array_map([self::class, 'peer'], $list));
    }

    public static function media(?array $m): ?MediaInfo
    {
        if (!$m || empty($m['type'])) {
            return null;
        }

        return new MediaInfo(
            type:     (string) $m['type'],
            mime:     isset($m['mime']) ? (string) $m['mime'] : null,
            size:     isset($m['size']) ? (int) $m['size'] : null,
            name:     isset($m['name']) ? (string) $m['name'] : null,
            duration: isset($m['duration']) ? (int) round((float) $m['duration']) : null,
        );
    }

    /**
     * tgcli message dict. The chat comes from the message itself ("chat" key,
     * present in `updates`) or from the surrounding response (`read`, `search`).
     */
    public static function message(array $m, ?Peer $chat = null): InboundMessage
    {
        $chat ??= isset($m['chat']) && is_array($m['chat'])
            ? self::peer($m['chat'])
            : new Peer((string) ($m['peer_id'] ?? ''), Peer::KIND_OTHER, (string) ($m['peer_id'] ?? ''));

        $sender = isset($m['sender']) && is_array($m['sender']) ? self::peer($m['sender']) : null;

        // Private chats: tgcli may not have a sender entity loaded — the chat is the sender.
        if ($sender === null && $chat->isPrivate() && empty($m['out'])) {
            $sender = $chat;
        }

        return new InboundMessage(
            id:        (string) ($m['id'] ?? ''),
            chat:      $chat,
            sender:    $sender,
            date:      self::date($m['date'] ?? null) ?? new DateTimeImmutable(),
            text:      (string) ($m['text'] ?? ''),
            outgoing:  (bool) ($m['out'] ?? false),
            replyTo:   isset($m['reply_to']) ? (string) $m['reply_to'] : null,
            mentioned: (bool) ($m['mentioned'] ?? false),
            media:     self::media($m['media'] ?? null),
        );
    }

    /**
     * @return InboundMessage[]
     */
    public static function messages(array $list, ?Peer $chat = null): array
    {
        return array_values(array_map(fn (array $m) => self::message($m, $chat), $list));
    }

    /**
     * tgcli dialog row: entity dict + {unread, pinned, date, last_message}
     */
    public static function dialog(array $d): Dialog
    {
        return new Dialog(
            peer:         self::peer($d),
            unread:       (int) ($d['unread'] ?? 0),
            pinned:       (bool) ($d['pinned'] ?? false),
            lastActivity: self::date($d['date'] ?? null),
            lastMessage:  isset($d['last_message']) ? (string) $d['last_message'] : null,
        );
    }

    /**
     * @return Dialog[]
     */
    public static function dialogs(array $list): array
    {
        return array_values(array_map([self::class, 'dialog'], $list));
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
