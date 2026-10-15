<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Contracts\Chat\ChatStatusServiceInterface;
use App\Contracts\Chat\InputPoolServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundMessage;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundSettings;
use App\Services\Agent\Capabilities\Messenger\MessengerFormatter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * Puts accepted inbound messages where the agent will see them, and wakes it
 * when one of them is a call.
 *
 * Where:
 *   pool    — when the capability's "Route to input pool" is on AND the preset
 *             runs in pool mode. One pool item per message (the pool overwrites
 *             by source name, so names are unique per message).
 *   history — otherwise. ONE user message per batch, not one per Telegram
 *             message: five quick messages from someone become one turn.
 *
 * Wake:
 *   - a batch wakes the agent when any message in it wakes (InboundSettings)
 *   - the preset's loop is running → nothing to do, the next cycle sees it
 *   - otherwise a single-mode cycle is started; if the agent is mid-cycle
 *     (locked) the wake is remembered and retried by the poller — without that,
 *     a message arriving while the agent thinks would sit until something else
 *     woke it
 *
 * Never runs commands found in message text — this is a third party's text.
 * (ChatService::createMessage() may run commands for admin users; inbound
 * messages deliberately do not go through it.)
 */
class InboundRouter
{
    /** Message metadata 'source'. Worth adding to Message as SOURCE_MESSENGER. */
    public const SOURCE = 'messenger';

    private const PENDING_WAKE_PREFIX = 'messenger_pending_wake_';
    private const PENDING_WAKE_TTL    = 6 * 3600;

    public function __construct(
        protected InputPoolServiceInterface $pool,
        protected Message $messageModel,
        protected AgentJobServiceFactoryInterface $jobs,
        protected ChatStatusServiceInterface $chatStatus,
        protected MessengerFormatter $formatter,
        protected MediaInterpreter $media,
        protected CacheRepository $cache,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @param InboundMessage[] $messages Already filtered by InboundSettings::accepts().
     */
    public function deliver(
        AiPreset $preset,
        MessengerProviderInterface $provider,
        InboundSettings $settings,
        array $messages,
    ): DeliveryReport {
        if ($messages === []) {
            return new DeliveryReport();
        }

        $presetId = (int) $preset->getId();
        $usePool  = $settings->sendToPool && $this->pool->isEnabled($preset);
        $wake     = false;
        $blocks   = [];
        $meta     = [];

        foreach ($messages as $m) {
            $block = $this->formatter->inboundBlock($m, $this->interpretMedia($preset, $provider, $m));
            $wake  = $wake || $settings->wakes($m);

            $meta[] = [
                'chat'       => $m->chat->address(),
                'chat_id'    => $m->chat->id,
                'sender'     => $m->author()->address(),
                'sender_id'  => $m->author()->id,
                'message_id' => $m->id,
                'private'    => $m->isPrivate(),
                'mentioned'  => $m->mentioned,
            ];

            if ($usePool) {
                $this->pool->add($presetId, $this->poolSourceName($provider, $m), $block);
            } else {
                $blocks[] = $block;
            }
        }

        $baseMeta = [
            'source'   => self::SOURCE,
            'provider' => $provider->getDriverName(),
            'messages' => $meta,
            'wake'     => $wake,
        ];

        $created = null;

        if ($usePool) {
            // Same as ChatService::sendUserMessage(dispatch: true): a call
            // flushes the pool into a turn; anything else waits in the pool for
            // the next cycle, which flushes it anyway.
            if ($wake) {
                $json = $this->pool->getAllAsJSON($preset);
                $this->pool->clear($presetId, true);
                if ($json !== null) {
                    $created = $this->createUserMessage($presetId, $json, $baseMeta);
                }
            }
        } else {
            $content = $this->header($provider, count($blocks)) . "\n\n" . implode("\n\n", $blocks);
            $created = $this->createUserMessage($presetId, $content, $baseMeta);
        }

        $woke = $wake ? $this->wake($preset) : false;

        return new DeliveryReport(count($messages), $usePool, $wake, $woke, $created?->id ?? null);
    }

    /**
     * Retry a wake that failed because the agent was busy. Called by the poller
     * on every pass, before fetching.
     */
    public function retryPendingWake(AiPreset $preset): bool
    {
        $key = self::PENDING_WAKE_PREFIX . $preset->getId();

        if (!$this->cache->has($key)) {
            return false;
        }

        if ($this->chatStatus->getPresetStatus((int) $preset->getId())) {
            $this->cache->forget($key); // loop is running now — it will see the message
            return false;
        }

        $svc = $this->jobs->make();
        if ($svc->isLocked((int) $preset->getId())) {
            return false; // still busy, keep the flag
        }

        $started = $svc->start((int) $preset->getId(), true);
        if ($started) {
            $this->cache->forget($key);
        }

        return $started;
    }

    /**
     * @return bool Whether a cycle was started now.
     */
    private function wake(AiPreset $preset): bool
    {
        $presetId = (int) $preset->getId();

        // Loop running: it picks the message up on its next cycle. Starting
        // another job here would risk a second parallel chain.
        if ($this->chatStatus->getPresetStatus($presetId)) {
            return false;
        }

        if ($this->jobs->make()->start($presetId, true)) {
            return true;
        }

        $this->cache->put(self::PENDING_WAKE_PREFIX . $presetId, true, self::PENDING_WAKE_TTL);
        $this->logger->info('InboundRouter: agent busy, wake deferred', ['preset_id' => $presetId]);

        return false;
    }

    private function interpretMedia(AiPreset $preset, MessengerProviderInterface $provider, InboundMessage $m): ?string
    {
        if ($m->media === null) {
            return null;
        }

        try {
            return $this->media
                ->interpret($preset, $provider, $m->chat->address(), $m->id, $m->media)
                ?->render();
        } catch (\Throwable $e) {
            $this->logger->warning('InboundRouter: media interpretation failed', [
                'preset_id' => $preset->getId(),
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function header(MessengerProviderInterface $provider, int $count): string
    {
        $what = $count === 1 ? 'new message' : "{$count} new messages";

        return "[{$provider->getDisplayName()}] {$what}. "
            . 'Reply with the messenger tool to the address shown, if a reply is needed.';
    }

    private function poolSourceName(MessengerProviderInterface $provider, InboundMessage $m): string
    {
        // Unique per message: the pool overwrites items with the same name.
        return sprintf('%s %s #%s', $provider->getDriverName(), $m->author()->label(), $m->id);
    }

    private function createUserMessage(int $presetId, string $content, array $metadata): Message
    {
        return $this->messageModel->create([
            'role'               => 'user',
            'content'            => $content,
            'from_user_id'       => null,
            'preset_id'          => $presetId,
            'is_visible_to_user' => true,
            'metadata'           => $metadata,
        ]);
    }
}
