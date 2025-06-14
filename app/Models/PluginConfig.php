<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * Plugin Configuration Model
 *
 * Stores persistent configuration and state for system plugins
 *
 * @property int $id
 * @property string $plugin_name
 * @property bool $is_enabled
 * @property array|null $config_data
 * @property array|null $default_config
 * @property string $health_status
 * @property string|null $version
 * @property Carbon|null $last_test_at
 * @property bool $last_test_result
 * @property string|null $last_test_error
 * @property array|null $test_history
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PluginConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'plugin_name',
        'is_enabled',
        'config_data',
        'default_config',
        'health_status',
        'version',
        'last_test_at',
        'last_test_result',
        'last_test_error',
        'test_history',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config_data' => 'array',
        'default_config' => 'array',
        'last_test_at' => 'datetime',
        'last_test_result' => 'boolean',
        'test_history' => 'array',
    ];

    protected $attributes = [
        'is_enabled' => false,
        'health_status' => 'unknown',
        'last_test_result' => false,
    ];

    /**
     * Get or create plugin configuration
     *
     * @param string $pluginName
     * @param array $defaultConfig
     * @return self
     */
    public static function findOrCreateByName(string $pluginName, array $defaultConfig = []): self
    {
        return static::firstOrCreate(
            ['plugin_name' => $pluginName],
            [
                'is_enabled' => true, // Enable by default for new plugins
                'config_data' => $defaultConfig,
                'default_config' => $defaultConfig,
                'health_status' => 'unknown',
            ]
        );
    }

    /**
     * Update plugin configuration
     *
     * @param array $config
     * @return boolean
     */
    public function updateConfig(array $config): bool
    {
        return $this->update(['config_data' => $config]);
    }

    /**
     * Reset plugin configuration to default values
     *
     * @return bool
     */
    public function resetToDefaults(): bool
    {
        return $this->update([
            'config_data' => $this->default_config ?? [],
        ]);
    }

    /**
     * Toggle plugin enabled/disabled state
     *
     * @return bool
     */
    public function toggle(): bool
    {
        return $this->update(['is_enabled' => !$this->is_enabled]);
    }

    /**
     * Enable plugin
     *
     * @return boolean
     */
    public function enable(): bool
    {
        return $this->update(['is_enabled' => true]);
    }

    /**
     * Disable plugin
     *
     * @return boolean
     */
    public function disable(): bool
    {
        return $this->update(['is_enabled' => false]);
    }

    /**
     * Record test result for plugin
     *
     * @param bool $isWorking
     * @param float|null $responseTime Response time in milliseconds
     * @param string|null $errorMessage
     */
    public function recordTestResult(bool $isWorking, ?float $responseTime = null, ?string $errorMessage = null): void
    {
        $this->health_status = $isWorking ? 'healthy' : 'error';
        $this->last_test_at = now();
        $this->last_test_result = $isWorking;
        $this->last_test_error = $errorMessage;

        // Store additional test metadata
        $testData = [
            'is_working' => $isWorking,
            'response_time_ms' => $responseTime,
            'error_message' => $errorMessage,
            'tested_at' => now()->toISOString(),
        ];

        // Keep last 10 test results for history
        $testHistory = $this->test_history ?? [];
        array_unshift($testHistory, $testData);
        $this->test_history = array_slice($testHistory, 0, 10);

        $this->save();
    }

    /**
     * Check if plugin needs testing based on interval
     *
     * @param int $intervalMinutes
     * @return bool
     */
    public function needsTesting(int $intervalMinutes = 30): bool
    {
        if (!$this->last_test_at) {
            return true;
        }

        return $this->last_test_at->diffInMinutes(now()) >= $intervalMinutes;
    }

    /**
     * Get plugin statistics
     *
     * @return array
     */
    public static function getStatistics(): array
    {
        $total = self::count();
        $enabled = self::where('is_enabled', true)->count();
        $disabled = $total - $enabled;
        $healthy = self::where('health_status', 'healthy')->count();
        $error = self::where('health_status', 'error')->count();
        $warning = self::where('health_status', 'warning')->count();
        $unknown = $total - $healthy - $error - $warning;

        return [
            'total_plugins' => $total,
            'enabled_plugins' => $enabled,
            'disabled_plugins' => $disabled,
            'healthy_plugins' => $healthy,
            'error_plugins' => $error,
            'warning_plugins' => $warning,
            'unknown_plugins' => $unknown,
        ];
    }

    /**
     * Get overall health status
     *
     * @return array
     */
    public static function getOverallHealth(): array
    {
        $enabledPlugins = self::where('is_enabled', true)->get();

        if ($enabledPlugins->isEmpty()) {
            return [
                'overall_status' => 'unknown',
                'plugins' => []
            ];
        }

        $healthyCount = $enabledPlugins->where('health_status', 'healthy')->count();
        $errorCount = $enabledPlugins->where('health_status', 'error')->count();
        $warningCount = $enabledPlugins->where('health_status', 'warning')->count();
        $totalEnabled = $enabledPlugins->count();

        // Determine overall status
        $overallStatus = 'healthy';
        if ($errorCount > 0) {
            $overallStatus = 'error';
        } elseif ($warningCount > 0) {
            $overallStatus = 'warning';
        } elseif ($healthyCount === 0) {
            $overallStatus = 'unknown';
        }

        // Return format expected by frontend
        return [
            'overall_status' => $overallStatus,
            'plugins' => $enabledPlugins->map(function ($plugin) {
                return [
                    'name' => $plugin->plugin_name,
                    'health_status' => $plugin->health_status,
                    'last_test_at' => $plugin->last_test_at?->toISOString(),
                ];
            })->toArray(),
        ];
    }

    /**
     * Get average response time for working plugins
     *
     * @return float|null
     */
    public static function getAverageResponseTime(): ?float
    {
        $plugins = self::where('is_enabled', true)
                      ->where('health_status', 'healthy')
                      ->whereNotNull('test_history')
                      ->get();

        $responseTimes = [];

        foreach ($plugins as $plugin) {
            $history = $plugin->test_history;
            if (!empty($history)) {
                $latestTest = $history[0] ?? null;
                if ($latestTest && isset($latestTest['response_time_ms']) && $latestTest['is_working']) {
                    $responseTimes[] = $latestTest['response_time_ms'];
                }
            }
        }

        return empty($responseTimes) ? null : round(array_sum($responseTimes) / count($responseTimes), 2);
    }

    /**
     * Get plugins that need health check
     *
     * @param int $intervalMinutes
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPluginsNeedingHealthCheck(int $intervalMinutes = 30): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('is_enabled', true)
                   ->where(function ($query) use ($intervalMinutes) {
                       $query->whereNull('last_test_at')
                             ->orWhere('last_test_at', '<', now()->subMinutes($intervalMinutes));
                   })
                   ->get();
    }

    /**
     * Scope for enabled plugins only
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope for disabled plugins
     *
     * @param mixed $query
     * @return void
     */
    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope for healthy plugins only
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHealthy($query)
    {
        return $query->where('health_status', 'healthy');
    }

    /**
     * Scope for plugins with errors
     *
     * @param [type] $query
     * @return void
     */
    public function scopeWithErrors($query)
    {
        return $query->where('health_status', 'error');
    }

    /**
     * Get plugin uptime percentage (last 24 hours)
     *
     * @return float
     */
    public function getUptimePercentage(): float
    {
        if (empty($this->test_history)) {
            return 0.0;
        }

        // Get tests from last 24 hours
        $oneDayAgo = now()->subDay();
        $recentTests = array_filter($this->test_history, function ($test) use ($oneDayAgo) {
            $testTime = \Carbon\Carbon::parse($test['tested_at']);
            return $testTime->isAfter($oneDayAgo);
        });

        if (empty($recentTests)) {
            return 0.0;
        }

        $successfulTests = array_filter($recentTests, function ($test) {
            return $test['is_working'] ?? false;
        });

        return round((count($successfulTests) / count($recentTests)) * 100, 2);
    }

}
