<?php

namespace App\Listeners;

use App\Contracts\Agent\Capabilities\MessengerServiceInterface;
use App\Contracts\Agent\Capabilities\RepliesToMessagesInterface;
use App\Events\AgentSpeakEvent;
use App\Services\Agent\Capabilities\Messenger\Inbound\ReplyRouteResolver;
use Psr\Log\LoggerInterface;

/**
 * The agent's [speak] also reaches the person who called it from a messenger.
 *
 * Speak means "say this to whoever I'm talking to". When that someone wrote
 * from Telegram, the words go back there too — the agent doesn't need to know
 * an address or switch tools. The web chat still shows the reply as before
 * (AgentActionsHandler writes it before firing the event).
 *
 * Who the interlocutor is comes from ReplyRouteResolver: the latest real
 * incoming message decides. A web message ends the route; so does silence
 * longer than the reply window.
 *
 * Runs inside the agent's cycle, so it never throws: a failed send is logged,
 * the cycle goes on.
 *
 * Auto-discovered by Laravel (type-hinted handle()).
 */
class RouteSpeechToMessenger
{
    /** Telegram's limit is 4096; leave room. */
    private const CHUNK = 4000;

    public function __construct(
        protected MessengerServiceInterface $messenger,
        protected ReplyRouteResolver $routes,
        protected LoggerInterface $logger,
    ) {
    }

    public function handle(AgentSpeakEvent $event): void
    {
        $text = trim($event->text);
        if ($text === '') {
            return;
        }

        $preset   = $event->preset;
        $presetId = (int) $preset->getId();

        try {
            $settings = $this->messenger->inboundSettings($preset);
            if (!$settings->isEnabled() || !$settings->replyViaSpeak) {
                return;
            }

            $route = $this->routes->resolve($presetId, $settings->replyWindow);
            if ($route === null) {
                return;
            }

            $provider = $this->messenger->provider($preset);
            if ($provider === null) {
                return;
            }

            // In a group the first piece answers the message that called the
            // agent (a threaded reply — with several conversations going on,
            // that's what says whom she's answering). In a private chat it's
            // obvious, and quoting every message would only be noise.
            $replyTo = empty($route['private'])
                && isset($route['message_id']) && $route['message_id'] !== ''
                && $provider instanceof RepliesToMessagesInterface
                ? (string) $route['message_id'] : null;

            foreach (self::chunks($text) as $i => $part) {
                $r = $i === 0 && $replyTo !== null
                    ? $provider->reply((string) $route['chat'], $replyTo, $part)
                    : $provider->send((string) $route['chat'], $part);
                if (!$r->success) {
                    $this->logger->warning('RouteSpeechToMessenger: send failed', [
                        'preset_id' => $presetId,
                        'chat'      => $route['chat'],
                        'code'      => $r->code,
                        'error'     => $r->error,
                    ]);
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RouteSpeechToMessenger: failed', [
                'preset_id' => $presetId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Split at paragraph, then line, then word boundaries.
     *
     * @return string[]
     */
    public static function chunks(string $text, int $size = self::CHUNK): array
    {
        $out = [];

        while (mb_strlen($text) > $size) {
            $head = mb_substr($text, 0, $size);
            $cut  = null;
            foreach (["\n\n", "\n", ' '] as $sep) {
                $pos = mb_strrpos($head, $sep);
                if ($pos !== false && $pos > $size / 2) {
                    $cut = $pos;
                    break;
                }
            }
            $cut ??= $size;

            $out[] = rtrim(mb_substr($text, 0, $cut));
            $text  = ltrim(mb_substr($text, $cut));
        }

        if ($text !== '') {
            $out[] = $text;
        }

        return $out;
    }
}
