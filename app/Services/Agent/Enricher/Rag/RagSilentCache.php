<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagSilentCacheInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

final class RagSilentCache implements RagSilentCacheInterface
{
    private const KEY_PREFIX  = 'rag_silent_';
    private const TTL_SECONDS = 600;
    private const MODES       = ['normal', 'extended'];

    public function __construct(
        private readonly Cache           $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(int $presetId, string $contextMode): ?RagAssembly
    {
        $raw = $this->cache->get($this->key($presetId, $contextMode));
        if ($raw === null) {
            return null;
        }

        try {
            $assembly = unserialize($raw, ['allowed_classes' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('RagSilentCache: failed to unserialize entry', [
                'preset_id' => $presetId, 'mode' => $contextMode, 'error' => $e->getMessage(),
            ]);
            $this->cache->forget($this->key($presetId, $contextMode));
            return null;
        }

        if (!$assembly instanceof RagAssembly || $assembly->contextMode !== $contextMode) {
            $this->cache->forget($this->key($presetId, $contextMode));
            return null;
        }

        return $assembly;
    }

    public function put(int $presetId, string $contextMode, RagAssembly $assembly): void
    {
        $this->cache->put($this->key($presetId, $contextMode), serialize($assembly), self::TTL_SECONDS);
    }

    public function forget(int $presetId): void
    {
        foreach (self::MODES as $mode) {
            $this->cache->forget($this->key($presetId, $mode));
        }
    }

    private function key(int $presetId, string $contextMode): string
    {
        return self::KEY_PREFIX . $presetId . '_' . $contextMode;
    }
}
