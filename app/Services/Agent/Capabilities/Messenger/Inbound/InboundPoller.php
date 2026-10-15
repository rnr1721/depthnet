<?php

namespace App\Services\Agent\Capabilities\Messenger\Inbound;

use App\Contracts\Agent\Capabilities\MessengerServiceInterface;
use App\Contracts\Agent\Capabilities\ReceivesMessagesInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Models\AiPreset;
use App\Services\Agent\Capabilities\Messenger\DTO\MessengerResult;
use App\Services\Agent\Capabilities\Messenger\DTO\UpdatesBatch;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * One inbound pass for one preset:
 *
 *   inbound off?             → skip
 *   backing off?             → skip (flood wait, or account not logged in)
 *   pending wake from before → retry it
 *   fetchUpdates(cursor)     → baseline? save cursor, done
 *   filter by InboundSettings::accepts()
 *   InboundRouter::deliver()
 *   save cursor              ← only now: a crash before this replays the batch
 *                              instead of losing it (at-least-once)
 *
 * The cursor lives in plugin metadata, per preset, so it survives restarts and
 * is wiped with the rest of the preset's data.
 *
 * Concurrency: a per-preset cache lock when the cache store supports locks
 * (redis does), so a scheduled run and a --loop worker can't double-deliver.
 */
class InboundPoller
{
    public const META_PLUGIN = 'messenger_inbound';
    public const META_CURSOR = 'cursor';

    private const LOCK_PREFIX    = 'messenger_poll_';
    private const LOCK_TTL       = 300;
    private const BACKOFF_PREFIX = 'messenger_backoff_';

    /** Not logged in / not configured: retry rarely, the operator has to act. */
    private const BACKOFF_NOT_READY = 300;

    /** Transient failures: short pause. */
    private const BACKOFF_ERROR = 30;

    public function __construct(
        protected MessengerServiceInterface $messenger,
        protected InboundRouter $router,
        protected PluginMetadataServiceInterface $metadata,
        protected CacheRepository $cache,
        protected LoggerInterface $logger,
        protected int $limit = 50,
        protected int $perDialog = 20,
    ) {
    }

    public function poll(AiPreset $preset): PollReport
    {
        $presetId = (int) $preset->getId();

        $settings = $this->messenger->inboundSettings($preset);
        if (!$settings->isEnabled()) {
            return PollReport::skipped('inbound is off');
        }

        $until = (int) $this->cache->get(self::BACKOFF_PREFIX . $presetId, 0);
        if ($until > time()) {
            return PollReport::skipped('backing off for ' . ($until - time()) . 's');
        }

        $lock = $this->acquireLock($presetId);
        if ($lock === false) {
            return PollReport::skipped('another poll is running');
        }

        try {
            $this->router->retryPendingWake($preset);

            $provider = $this->messenger->provider($preset);
            if ($provider === null) {
                return PollReport::skipped('messenger not available');
            }
            if (!$provider instanceof ReceivesMessagesInterface) {
                return PollReport::skipped($provider->getDisplayName() . ' cannot receive messages');
            }

            $result = $provider->fetchUpdates($this->loadCursor($preset), $this->limit, $this->perDialog);

            if (!$result->success) {
                $this->backOff($presetId, $result);
                return PollReport::failed((string) $result->error, (string) $result->code);
            }

            /** @var UpdatesBatch $batch */
            $batch = $result->value;

            if ($batch->baseline) {
                $this->saveCursor($preset, $batch->cursor);
                return PollReport::baseline();
            }

            $accepted = array_values(array_filter($batch->messages, [$settings, 'accepts']));
            $report   = $this->router->deliver($preset, $provider, $settings, $accepted);

            $this->saveCursor($preset, $batch->cursor);

            return PollReport::delivered(count($batch->messages), $report, $batch->truncated);
        } finally {
            if (is_object($lock)) {
                $lock->release();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCursor(AiPreset $preset): array
    {
        $raw = $this->metadata->get($preset, self::META_PLUGIN, self::META_CURSOR, null);

        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Stored as a JSON string — plugin metadata is happiest with scalars.
     */
    public function saveCursor(AiPreset $preset, array $cursor): void
    {
        $this->metadata->set($preset, self::META_PLUGIN, self::META_CURSOR, json_encode($cursor));
    }

    /**
     * Forget the cursor: the next pass takes a fresh baseline (nothing replayed).
     */
    public function resetCursor(AiPreset $preset): void
    {
        $this->metadata->remove($preset, self::META_PLUGIN, self::META_CURSOR);
    }

    private function backOff(int $presetId, MessengerResult $result): void
    {
        $seconds = match ($result->code) {
            MessengerResult::CODE_RATE_LIMITED    => max(5, (int) $result->retryAfter),
            MessengerResult::CODE_NOT_AUTHORIZED,
            MessengerResult::CODE_NOT_CONFIGURED  => self::BACKOFF_NOT_READY,
            MessengerResult::CODE_BUSY            => 0,   // just try next pass
            default                               => self::BACKOFF_ERROR,
        };

        if ($seconds > 0) {
            $this->cache->put(self::BACKOFF_PREFIX . $presetId, time() + $seconds, $seconds);
        }

        $level = in_array($result->code, [MessengerResult::CODE_BUSY, MessengerResult::CODE_RATE_LIMITED], true)
            ? 'info' : 'warning';

        $this->logger->{$level}('InboundPoller: fetch failed', [
            'preset_id' => $presetId,
            'code'      => $result->code,
            'error'     => $result->error,
            'backoff'   => $seconds,
        ]);
    }

    /**
     * @return object|null|false lock object; null when the store has no locks;
     *                           false when someone else holds it
     */
    private function acquireLock(int $presetId): object|null|false
    {
        // Laravel's Repository forwards lock() to stores that support it.
        if (!is_callable([$this->cache, 'lock'])) {
            return null;
        }

        try {
            $lock = $this->cache->lock(self::LOCK_PREFIX . $presetId, self::LOCK_TTL);
        } catch (\BadMethodCallException) {
            return null; // store without locks (array/file in some setups)
        }

        return $lock->get() ? $lock : false;
    }
}
