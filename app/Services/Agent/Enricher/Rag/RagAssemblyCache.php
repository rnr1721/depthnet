<?php

namespace App\Services\Agent\Enricher\Rag;

use App\Contracts\Agent\Enricher\Rag\RagAssemblyCacheInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

final class RagAssemblyCache implements RagAssemblyCacheInterface
{
    private const KEY_PREFIX = 'rag_warm_';

    /**
     * TTL safety net. Speech-based invalidation is the primary mechanism; this
     * only bounds how long a warm assembly can survive if that invalidation is
     * missed. Deliberately short — a warm assembly older than this is suspect.
     */
    private const TTL_SECONDS = 600;

    /**
     * Modes a preset can be in — used by forget() to clear all variants without
     * needing to know which mode is currently cached.
     */
    private const MODES = ['normal', 'extended'];

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
            // Corrupt/incompatible payload (e.g. class changed between deploys).
            // Treat as a miss — the cycle assembles fresh. Drop the bad entry.
            $this->logger->warning('RagAssemblyCache: failed to unserialize warm entry', [
                'preset_id' => $presetId,
                'mode'      => $contextMode,
                'error'     => $e->getMessage(),
            ]);
            $this->cache->forget($this->key($presetId, $contextMode));
            return null;
        }

        if (!$assembly instanceof RagAssembly) {
            $this->cache->forget($this->key($presetId, $contextMode));
            return null;
        }

        // Defensive: the mode stored inside must match the mode we keyed on.
        // Guards against a key/content drift bug — see RagAssembly::contextMode.
        if ($assembly->contextMode !== $contextMode) {
            $this->logger->warning('RagAssemblyCache: mode mismatch, discarding', [
                'preset_id'   => $presetId,
                'keyed_mode'  => $contextMode,
                'stored_mode' => $assembly->contextMode,
            ]);
            $this->cache->forget($this->key($presetId, $contextMode));
            return null;
        }

        return $assembly;
    }

    public function put(int $presetId, string $contextMode, RagAssembly $assembly): void
    {
        $this->cache->put(
            $this->key($presetId, $contextMode),
            serialize($assembly),
            self::TTL_SECONDS,
        );
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
