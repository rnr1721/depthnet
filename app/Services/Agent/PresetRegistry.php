<?php

namespace App\Services\Agent;

use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Exceptions\PresetNotFoundException;
use App\Exceptions\PresetNotActiveException;
use App\Exceptions\NoActivePresetsException;
use App\Exceptions\PresetInstanceException;
use App\Models\AiPreset;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Registry for AI presets with enhanced caching and error handling
 *
 * Provides centralized access to AI presets with intelligent caching,
 * fallback mechanisms, and integration with the engine registry.
 */
class PresetRegistry implements PresetRegistryInterface
{
    protected const CACHE_KEY = 'ai_presets';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        protected EngineRegistryInterface $engineRegistry,
        protected AiPreset $aiPresetModel,
        protected CacheManager $cache,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getActivePresets(): Collection
    {
        return $this->cache->remember(self::CACHE_KEY . '_active', self::CACHE_TTL, function () {
            return $this->aiPresetModel->active()->orderBy('name')->get();
        });
    }

    /**
     * @inheritDoc
     */
    public function getPreset(int $id): AiPreset
    {
        $preset = $this->cache->remember(self::CACHE_KEY . "_preset_{$id}", self::CACHE_TTL, function () use ($id) {
            return $this->aiPresetModel->find($id);
        });

        if (!$preset) {
            throw new PresetNotFoundException("Preset with ID $id not found");
        }

        if (!$preset->isActive()) {
            throw new PresetNotActiveException("Preset with ID $id is not active");
        }

        return $preset;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultPreset(): AiPreset
    {
        $preset = $this->cache->remember(self::CACHE_KEY . '_default', self::CACHE_TTL, function () {
            return $this->aiPresetModel->default()->active()->first();
        });

        if (!$preset) {
            // Fallback to first active preset
            $preset = $this->getActivePresets()->first();
        }

        if (!$preset) {
            throw new NoActivePresetsException("No active presets found");
        }

        return $preset;
    }

    /**
     * @inheritDoc
     */
    public function createInstance(int $presetId): AIModelEngineInterface
    {
        $preset = $this->getPreset($presetId);

        // Validate that the engine exists and is available
        if (!$this->engineRegistry->has($preset->getEngineName())) {
            throw new PresetInstanceException("Engine '{$preset->getEngineName()}' is not available for preset '{$preset->getName()}'");
        }

        // Check if engine is enabled in configuration
        if (!$this->engineRegistry->isEngineEnabled($preset->getEngineName())) {
            throw new PresetInstanceException("Engine '{$preset->getEngineName()}' is disabled in configuration");
        }

        return $this->engineRegistry->createInstance(
            $preset->getEngineName(),
            $preset->getEngineConfig()
        );
    }

    /**
     * @inheritDoc
     */
    public function refresh(): void
    {
        $this->cache->forget(self::CACHE_KEY . '_active');
        $this->cache->forget(self::CACHE_KEY . '_default');

        // Clear individual preset caches
        $this->clearPresetCaches();

        $this->logger->info('PresetRegistry: Cache refreshed');
    }

    /**
     * @inheritDoc
     */
    public function getPresetsByEngine(string $engineName): Collection
    {
        return $this->cache->remember(self::CACHE_KEY . "_engine_{$engineName}", self::CACHE_TTL, function () use ($engineName) {
            return $this->aiPresetModel->active()
                ->where('engine_name', $engineName)
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * @inheritDoc
     */
    public function getAllPresets(): Collection
    {
        return $this->cache->remember(self::CACHE_KEY . '_all', self::CACHE_TTL, function () {
            return $this->aiPresetModel->orderBy('name')->get();
        });
    }

    /**
     * @inheritDoc
     */
    public function findByName(string $name): ?AiPreset
    {
        return $this->getActivePresets()->firstWhere('name', $name);
    }

    /**
     * @inheritDoc
     */
    public function getPresetOrDefault(?int $presetId = null): AiPreset
    {
        if ($presetId === null) {
            return $this->getDefaultPreset();
        }

        try {
            return $this->getPreset($presetId);
        } catch (PresetNotFoundException|PresetNotActiveException $e) {
            $this->logger->warning('PresetRegistry: Falling back to default preset', [
                'requested_preset_id' => $presetId,
                'error' => $e->getMessage()
            ]);

            return $this->getDefaultPreset();
        }
    }

    /**
     * @inheritDoc
     */
    public function createInstanceOrDefault(?int $presetId = null): AIModelEngineInterface
    {
        $preset = $this->getPresetOrDefault($presetId);
        return $this->createInstance($preset->getId());
    }

    /**
     * @inheritDoc
     */
    public function validatePreset(int $presetId): array
    {
        $preset = $this->getPreset($presetId);

        // Check if engine exists
        if (!$this->engineRegistry->has($preset->getEngineName())) {
            return ['engine' => "Engine '{$preset->getEngineName()}' not found"];
        }

        // Validate engine configuration
        $configErrors = $this->engineRegistry->validateEngineConfig(
            $preset->getEngineName(),
            $preset->getEngineConfig()
        );

        return $configErrors;
    }

    /**
     * @inheritDoc
     */
    public function testPresetConnection(int $presetId): array
    {
        try {
            $preset = $this->getPreset($presetId);

            // Create temporary instance to test
            $engine = $this->createInstance($presetId);

            $startTime = microtime(true);
            $result = false;

            if (method_exists($engine, 'testConnection')) {
                $result = $engine->testConnection();
            }

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => $result,
                'response_time' => $responseTime,
                'preset_name' => $preset->getName(),
                'engine_name' => $preset->getEngineName()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getPresetStats(): array
    {
        $allPresets = $this->getAllPresets();
        $activePresets = $this->getActivePresets();

        $engineStats = [];
        foreach ($activePresets as $preset) {
            $engineName = $preset->getEngineName();
            if (!isset($engineStats[$engineName])) {
                $engineStats[$engineName] = 0;
            }
            $engineStats[$engineName]++;
        }

        return [
            'total_presets' => $allPresets->count(),
            'active_presets' => $activePresets->count(),
            'inactive_presets' => $allPresets->count() - $activePresets->count(),
            'default_preset' => $this->getDefaultPreset()->getName(),
            'presets_by_engine' => $engineStats,
            'available_engines' => array_keys($this->engineRegistry->getEnabledEngines())
        ];
    }

    /**
     * @inheritDoc
     */
    public function refreshPreset(int $presetId): void
    {
        $this->cache->forget(self::CACHE_KEY . "_preset_{$presetId}");

        // Also refresh lists that might include this preset
        $this->cache->forget(self::CACHE_KEY . '_active');
        $this->cache->forget(self::CACHE_KEY . '_all');
        $this->cache->forget(self::CACHE_KEY . '_default');

        // Refresh engine-specific cache if we can determine the engine
        try {
            $preset = $this->aiPresetModel->find($presetId);
            if ($preset) {
                $this->cache->forget(self::CACHE_KEY . "_engine_{$preset->getEngineName()}");
            }
        } catch (\Exception $e) {
            // Ignore errors when refreshing cache
        }
    }

    /**
     * @inheritDoc
     */
    public function isPresetUsable(int $presetId): bool
    {
        try {
            $preset = $this->getPreset($presetId);
            return $this->engineRegistry->has($preset->getEngineName()) &&
                   $this->engineRegistry->isEngineEnabled($preset->getEngineName());
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getUsablePresets(): Collection
    {
        return $this->getActivePresets()->filter(function ($preset) {
            return $this->engineRegistry->has($preset->getEngineName()) &&
                   $this->engineRegistry->isEngineEnabled($preset->getEngineName());
        });
    }

    /**
     * @inheritDoc
     */
    public function autoRepairPresets(): array
    {
        $repairedPresets = [];
        $activePresets = $this->getActivePresets();

        foreach ($activePresets as $preset) {
            if (!$this->engineRegistry->has($preset->getEngineName()) ||
                !$this->engineRegistry->isEngineEnabled($preset->getEngineName())) {

                // Disable the preset
                $preset = $this->aiPresetModel->find($preset->getId());
                if ($preset) {
                    $preset->is_active = false;
                    $preset->save();

                    $repairedPresets[] = [
                        'preset_id' => $preset->getId(),
                        'preset_name' => $preset->getName(),
                        'engine_name' => $preset->getEngineName(),
                        'action' => 'disabled'
                    ];
                }
            }
        }

        if (!empty($repairedPresets)) {
            $this->refresh();

            $this->logger->info('PresetRegistry: Auto-repaired presets', [
                'repaired_count' => count($repairedPresets),
                'repaired_presets' => $repairedPresets
            ]);
        }

        return $repairedPresets;
    }

    /**
     * @inheritDoc
     */
    protected function clearPresetCaches(): void
    {
        // Get all preset IDs to clear individual caches
        try {
            $presetIds = $this->aiPresetModel->pluck('id')->toArray();
            foreach ($presetIds as $id) {
                $this->cache->forget(self::CACHE_KEY . "_preset_{$id}");
            }
        } catch (\Exception $e) {
            // If we can't get preset IDs, just log it
            $this->logger->warning('PresetRegistry: Failed to clear individual preset caches', [
                'error' => $e->getMessage()
            ]);
        }

        // Clear engine-specific caches
        try {
            $engines = array_keys($this->engineRegistry->all());
            foreach ($engines as $engineName) {
                $this->cache->forget(self::CACHE_KEY . "_engine_{$engineName}");
            }
        } catch (\Exception $e) {
            // If we can't get engines, just log it
            $this->logger->warning('PresetRegistry: Failed to clear engine-specific caches', [
                'error' => $e->getMessage()
            ]);
        }

        // Clear general caches
        $this->cache->forget(self::CACHE_KEY . '_all');
    }

    /**
     * @inheritDoc
     */
    public function warmCache(): void
    {
        try {
            $this->getActivePresets();
            $this->getDefaultPreset();

            $this->logger->info('PresetRegistry: Cache warmed up successfully');
        } catch (\Exception $e) {
            $this->logger->warning('PresetRegistry: Failed to warm cache', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
