<?php

namespace App\Services\Agent\Capabilities\Messenger\DTO;

use DateTimeImmutable;

/**
 * One message, as seen in a chat history or an inbound batch.
 *
 * $chat is where it was posted; $sender is who posted it (null for channel
 * posts and service messages). In a private chat the two are the same person.
 */
final class InboundMessage
{
    public function __construct(
        public readonly string $id,
        public readonly Peer $chat,
        public readonly ?Peer $sender,
        public readonly DateTimeImmutable $date,
        public readonly string $text = '',
        public readonly bool $outgoing = false,
        public readonly ?string $replyTo = null,
        public readonly bool $mentioned = false,
        public readonly ?MediaInfo $media = null,
    ) {
    }

    public function isPrivate(): bool
    {
        return $this->chat->isPrivate();
    }

    /** Who to attribute the message to: the sender, or the chat when unknown. */
    public function author(): Peer
    {
        return $this->sender ?? $this->chat;
    }

    /** Text with a media marker: "(voice 0:12)", "(photo) look at this". */
    public function displayText(): string
    {
        $label = $this->media?->label() ?? '';
        $text  = trim($this->text);

        if ($label !== '' && $text !== '') {
            return $label . ' ' . $text;
        }

        return $text !== '' ? $text : ($label !== '' ? $label : '(no text)');
    }

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'chat'      => $this->chat->toArray(),
            'sender'    => $this->sender?->toArray(),
            'date'      => $this->date->format(DATE_ATOM),
            'text'      => $this->text,
            'outgoing'  => $this->outgoing,
            'reply_to'  => $this->replyTo,
            'mentioned' => $this->mentioned,
            'media'     => $this->media?->toArray(),
        ];
    }
}
