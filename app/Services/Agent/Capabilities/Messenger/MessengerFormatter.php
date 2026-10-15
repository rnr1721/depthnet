<?php

namespace App\Services\Agent\Capabilities\Messenger;

use App\Services\Agent\Capabilities\Messenger\DTO\AuthStatus;
use App\Services\Agent\Capabilities\Messenger\DTO\ChatHistory;
use App\Services\Agent\Capabilities\Messenger\DTO\ChatInfo;
use App\Services\Agent\Capabilities\Messenger\DTO\Dialog;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundMessage;
use App\Services\Agent\Capabilities\Messenger\DTO\Peer;
use App\Services\Agent\Capabilities\Messenger\DTO\SentMessage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Renders messenger DTOs as text for the agent.
 *
 * One format for every provider: the agent sees the same shapes whether the
 * account is Telegram or anything added later. Every peer is printed with an
 * address the agent can paste straight into the next command, and every
 * message with its id (#4215) for follow-ups like fetching its media.
 *
 * Used by MessengerPlugin now, and by the inbound router in step 6.
 */
class MessengerFormatter
{
    private const ICONS = [
        Peer::KIND_USER    => '[user]',
        Peer::KIND_BOT     => '[bot]',
        Peer::KIND_GROUP   => '[group]',
        Peer::KIND_CHANNEL => '[channel]',
        Peer::KIND_OTHER   => '[chat]',
    ];

    public function __construct(
        protected ?string $timezone = null,
        protected int $maxTextLength = 2000,
    ) {
    }

    public function peer(Peer $peer): string
    {
        return (self::ICONS[$peer->kind] ?? '[chat]') . ' ' . $peer->label();
    }

    /**
     * @param Peer[] $peers
     */
    public function resolveResult(string $query, array $peers): string
    {
        $n = count($peers);

        if ($n === 0) {
            return "No matches for \"{$query}\" in this account. The target does not exist here — "
                . 'report that (the request may rest on a wrong assumption) rather than guessing an address.';
        }

        $lines = ["Matches for \"{$query}\": {$n}"];
        foreach (array_values($peers) as $i => $p) {
            $lines[] = sprintf('%d. %s', $i + 1, $this->peer($p));
        }

        return implode("\n", $lines);
    }

    /**
     * @param Peer[] $peers
     */
    public function contacts(array $peers): string
    {
        if ($peers === []) {
            return 'The address book is empty.';
        }

        $lines = ['Contacts: ' . count($peers)];
        foreach ($peers as $p) {
            $lines[] = '- ' . $this->peer($p);
        }

        return implode("\n", $lines);
    }

    /**
     * @param Dialog[] $dialogs
     */
    public function dialogs(array $dialogs, string $heading = 'Dialogs'): string
    {
        if ($dialogs === []) {
            return $heading === 'Unread' ? 'No unread messages.' : 'No dialogs.';
        }

        $lines = [$heading . ': ' . count($dialogs)];
        foreach ($dialogs as $d) {
            $line = '- ' . $this->peer($d->peer);
            if ($d->unread > 0) {
                $line .= " — {$d->unread} unread";
            }
            if ($d->lastActivity) {
                $line .= ' — ' . $this->time($d->lastActivity);
            }
            if ($d->lastMessage !== null && $d->lastMessage !== '') {
                $line .= ': ' . $this->clip($d->lastMessage, 200);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public function history(ChatHistory $history, string $what = 'last'): string
    {
        $n = count($history->messages);

        $head = $what === 'search'
            ? "Search in {$this->peer($history->chat)} — {$n} found"
            : "Chat {$this->peer($history->chat)} — {$what} {$n} messages";

        if ($n === 0) {
            return $head . "\n(nothing)";
        }

        $lines = [$head];
        $hasPerceivableMedia = false;
        foreach ($history->messages as $m) {
            $lines[] = $this->messageLine($m, withChat: false);
            $hasPerceivableMedia = $hasPerceivableMedia
                || ($m->media !== null && ($m->media->isSpeech() || $m->media->isImage()));
        }

        if ($hasPerceivableMedia) {
            $lines[] = "To hear a voice message or see a photo: [messenger media]{$history->chat->address()} <#id>[/messenger]";
        }

        return implode("\n", $lines);
    }

    /**
     * Incoming message as a block, keeping the sender's line breaks:
     *
     *   #4215 12:34 [user] Alena (@alena_p):
     *   (voice 0:12) transcript: are you free tonight?
     *
     *   #4216 12:35 [group] Dev chat (-100…) · Bob (@bob) [mentions you]:
     *   @ada what do you think?
     *
     * $mediaText replaces the bare media label when the media was interpreted.
     */
    public function inboundBlock(InboundMessage $m, ?string $mediaText = null): string
    {
        $author = $this->peer($m->author());
        $where  = $m->isPrivate() ? $author : $this->peer($m->chat) . ' · ' . $author;
        $flags  = $m->mentioned && !$m->isPrivate() ? ' [mentions you]' : '';

        $parts = [];
        if ($m->media !== null) {
            $parts[] = trim($m->media->label() . ($mediaText !== null ? ' ' . $mediaText : ''));
        }
        $text = trim($m->text);
        if ($text !== '') {
            $parts[] = mb_strlen($text) > $this->maxTextLength
                ? mb_substr($text, 0, $this->maxTextLength) . '…'
                : $text;
        }

        return sprintf(
            "#%s %s %s%s:\n%s",
            $m->id,
            $this->time($m->date),
            $where,
            $flags,
            $parts === [] ? '(no text)' : implode("\n", $parts),
        );
    }

    /**
     * "#4215 15.10 12:34 Alena: hi" — or with the chat, for mixed feeds:
     * "#4215 15.10 12:34 [group] Dev chat (-100…) · Alena: hi"
     */
    public function messageLine(InboundMessage $m, bool $withChat = true): string
    {
        $who  = $m->outgoing ? 'Me' : $m->author()->name;
        $where = $withChat && !$m->isPrivate() ? $this->peer($m->chat) . ' · ' : '';

        if ($withChat && $m->isPrivate()) {
            $who = $m->outgoing ? 'Me' : $this->peer($m->author());
        }

        $flags = $m->mentioned && !$m->isPrivate() ? ' [mentions you]' : '';

        return sprintf(
            '#%s %s %s%s%s: %s',
            $m->id,
            $this->time($m->date),
            $where,
            $who,
            $flags,
            $this->clip($m->displayText(), $this->maxTextLength),
        );
    }

    public function sent(SentMessage $s): string
    {
        return "Sent to {$this->peer($s->chat)}" . ($s->messageId !== '' ? ", message #{$s->messageId}." : '.');
    }

    public function info(ChatInfo $info): string
    {
        $lines = [$this->peer($info->peer), 'Address: ' . $info->peer->address()];
        if ($info->members !== null) {
            $lines[] = 'Members: ' . $info->members;
        }
        if ($info->about !== null && trim($info->about) !== '') {
            $lines[] = 'About: ' . $this->clip($info->about, 1000);
        }

        return implode("\n", $lines);
    }

    public function account(AuthStatus $status, string $providerName): string
    {
        return $status->authorized && $status->account !== null
            ? "My {$providerName} account: {$status->account->label()}"
            : "{$providerName}: not logged in" . ($status->message ? " ({$status->message})" : '') . '.';
    }

    public function time(DateTimeImmutable $date): string
    {
        $tz    = new DateTimeZone($this->timezone ?: date_default_timezone_get());
        $local = $date->setTimezone($tz);
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        return $local->format('Y-m-d') === $today ? $local->format('H:i') : $local->format('d.m H:i');
    }

    private function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
