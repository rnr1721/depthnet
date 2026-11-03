<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

/**
 * Someone or something you can address: a person, a bot, a group, a channel.
 *
 * $id is a string in the provider's own notation (Telegram uses marked numeric
 * ids like "-1001820894363"; Matrix would use "@user:server"). It is always a
 * valid target for the same provider, as is address().
 */
final class Peer
{
    public const KIND_USER    = 'user';
    public const KIND_BOT     = 'bot';
    public const KIND_GROUP   = 'group';
    public const KIND_CHANNEL = 'channel';
    public const KIND_OTHER   = 'chat';

    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $username = null,
        public readonly bool $isSelf = false,
    ) {
    }

    /** Preferred target for other calls: @username when there is one, else the id. */
    public function address(): string
    {
        return $this->username !== null && $this->username !== ''
            ? '@' . $this->username
            : $this->id;
    }

    /** One-to-one chat with a person or a bot. */
    public function isPrivate(): bool
    {
        return $this->kind === self::KIND_USER || $this->kind === self::KIND_BOT;
    }

    /** "Alena (@alena_p)" / "Dev chat (-1001820894363)" — for human/agent-facing text. */
    public function label(): string
    {
        return $this->name . ' (' . $this->address() . ')';
    }

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'kind'     => $this->kind,
            'name'     => $this->name,
            'username' => $this->username,
            'address'  => $this->address(),
            'is_self'  => $this->isSelf,
        ];
    }
}
