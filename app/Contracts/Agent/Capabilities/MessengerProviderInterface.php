<?php

namespace App\Contracts\Agent\Capabilities;

use App\Services\Agent\Capabilities\Messenger\DTO\AuthStatus;
use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;

/**
 * Contract for messenger capability providers (Telegram, WhatsApp, Matrix, ...).
 *
 * Same shape as SttProviderInterface: the base carries only what EVERY
 * messenger can honour — status, finding a target, reading, sending. Everything
 * else is an optional interface detected via instanceof, so a provider that
 * cannot search or list contacts simply doesn't offer it, and the plugin's tool
 * schema shrinks accordingly instead of exposing methods that always fail.
 *
 * Unlike vision or embedding providers, a messenger provider is bound to one
 * preset: it owns an account session (files, tokens) that belongs to that
 * preset. MessengerRegistry passes the preset id at construction; a prototype
 * built without one (for config metadata) refuses every operation.
 *
 * Failure convention mirrors the other capabilities: ordinary failures (not
 * authorized, target not found, rate limit) come back as MessengerResult::fail()
 * with a normalized code and a human-readable message — never as exceptions.
 *
 * Addresses ("targets") are strings in the messenger's own notation:
 * "@username" or a peer id for Telegram, "@user:server" for Matrix, etc.
 * Every Peer returned by a provider has an address() the same provider accepts.
 *
 * Optional interfaces:
 * @see AuthenticatesInteractivelyInterface  multi-step login (phone/code/2FA, QR)
 * @see ReceivesMessagesInterface            cursor-based inbound feed
 * @see ListsDialogsInterface                dialogs and unread overview
 * @see ListsContactsInterface               address book
 * @see SearchesMessagesInterface            search inside a chat
 * @see MarksReadInterface                   mark a chat as read
 * @see DescribesChatsInterface              info about a user/group/channel
 * @see DownloadsMediaInterface              fetch media of a message to disk
 */
interface MessengerProviderInterface extends CapabilityProviderInterface
{
    public const CAPABILITY = 'messenger';

    /**
     * Whether the account is usable right now, and which account it is.
     * Cheap enough for a GUI status badge and the capability Test button.
     */
    public function status(): AuthStatus;

    /**
     * Find real, addressable targets matching a name, username or id.
     *
     * The verification step before sending: an empty list means the target does
     * not exist in this account, which is an answer, not an error.
     *
     * @return MessengerResult value: Peer[]
     */
    public function resolve(string $query): MessengerResult;

    /**
     * Last messages of a chat, oldest first.
     *
     * @return MessengerResult value: ChatHistory
     */
    public function read(string $target, int $limit = 15): MessengerResult;

    /**
     * Send a text message.
     *
     * @return MessengerResult value: SentMessage
     */
    public function send(string $target, string $text): MessengerResult;
}
