<?php

namespace App\Services\Agent;

use App\Contracts\Agent\EnvironmentInfoServiceInterface;

class EnvironmentInfoService implements EnvironmentInfoServiceInterface
{
    /**
     * @inheritDoc
     */
    public function getEnvironmentInfo(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        return $this->buildEnvironmentInfo();
    }

    /**
     * @inheritDoc
     */
    public function getEnvironmentData(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $data = [
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'os' => PHP_OS_FAMILY,
            'database' => $this->getDatabaseInfo(),
        ];

        // Add optional data based on config
        if (config('ai.environment.include_memory', false)) {
            $data['memory_limit'] = ini_get('memory_limit');
        }

        if (config('ai.environment.include_cwd', false)) {
            $data['working_directory'] = getcwd();
        }

        if (config('ai.environment.include_load', false) && PHP_OS_FAMILY === 'Linux') {
            $data['system_load'] = $this->getSystemLoad();
        }

        if (config('ai.environment.include_disk', false)) {
            $data['disk_space'] = $this->getDiskSpace();
        }

        // Add custom fields from config
        $customFields = config('ai.environment.custom_fields', []);
        $data = array_merge($data, $customFields);

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function getDatabaseInfo(): string
    {
        try {
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");
            $database = config("database.connections.{$connection}.database");

            // For SQLite, show just the filename
            if ($driver === 'sqlite') {
                return "SQLite (" . basename($database) . ")";
            }

            // Include detailed info if enabled
            if (config('ai.environment.include_db_details', false)) {
                $host = config("database.connections.{$connection}.host");
                $port = config("database.connections.{$connection}.port");
                return ucfirst($driver) . " ({$database}@{$host}:{$port})";
            }

            // Basic info only
            return ucfirst($driver) . " ({$database})";

        } catch (\Exception $e) {
            return "Unknown";
        }
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        // Check global config
        if (!config('ai.environment.enabled', true)) {
            return false;
        }

        // Check if hidden in production
        if (config('ai.environment.security.hide_in_production', false) && app()->isProduction()) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getCustomEnvironmentInfo(array $options = []): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $info = [];
        $data = $this->getEnvironmentData();

        // Always include basic info unless explicitly disabled
        if ($options['include_basic'] ?? true) {
            $info[] = "Environment: " . $data['environment'];
            $info[] = "PHP: " . $data['php_version'];
            $info[] = "Laravel: " . $data['laravel_version'];
            $info[] = "OS: " . $data['os'];
            $info[] = "Database: " . $data['database'];
        }

        // Add optional fields based on options
        foreach ($options as $key => $value) {
            if ($value && isset($data[$key]) && !in_array($key, ['environment', 'php_version', 'laravel_version', 'os', 'database'])) {
                $label = ucwords(str_replace('_', ' ', $key));
                $info[] = "{$label}: " . $data[$key];
            }
        }

        return implode("\n", $info);
    }

    /**
     * Build environment information string
     *
     * @return string
     */
    private function buildEnvironmentInfo(): string
    {
        $data = $this->getEnvironmentData();
        $info = [];

        // Basic information
        $info[] = "Environment: " . $data['environment'];
        $info[] = "PHP: " . $data['php_version'];
        $info[] = "Laravel: " . $data['laravel_version'];
        $info[] = "OS: " . $data['os'];
        $info[] = "Database: " . $data['database'];

        // Optional information
        if (isset($data['memory_limit'])) {
            $info[] = "Memory Limit: " . $data['memory_limit'];
        }

        if (isset($data['working_directory'])) {
            $info[] = "Working Directory: " . $data['working_directory'];
        }

        if (isset($data['system_load'])) {
            $info[] = "System Load: " . $data['system_load'];
        }

        if (isset($data['disk_space'])) {
            $info[] = "Disk Space: " . $data['disk_space'];
        }

        // Custom fields
        $customFields = config('ai.environment.custom_fields', []);
        foreach ($customFields as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $info[] = "{$label}: {$value}";
        }

        return implode("\n", $info);
    }

    /**
     * Get system load average (Linux/Unix only)
     *
     * @return string
     */
    private function getSystemLoad(): string
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('sys_getloadavg')) {
            return 'N/A';
        }

        try {
            $load = sys_getloadavg();
            return sprintf('%.2f %.2f %.2f', $load[0], $load[1], $load[2]);
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Get disk space information
     *
     * @return string
     */
    private function getDiskSpace(): string
    {
        try {
            $bytes = disk_free_space('/');
            $total = disk_total_space('/');

            if ($bytes === false || $total === false) {
                return 'N/A';
            }

            $used = $total - $bytes;
            $usedPercent = round(($used / $total) * 100, 1);

            return sprintf(
                '%s / %s (%s%% used)',
                $this->formatBytes($bytes),
                $this->formatBytes($total),
                $usedPercent
            );
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Format bytes to human readable format
     *
     * @param integer $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return number_format($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
