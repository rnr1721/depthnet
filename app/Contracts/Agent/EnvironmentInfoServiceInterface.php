<?php

namespace App\Contracts\Agent;

interface EnvironmentInfoServiceInterface
{
    /**
     * Get complete environment information for AI context
     *
     * @return string
     */
    public function getEnvironmentInfo(): string;

    /**
     * Get basic environment data as array
     *
     * @return array
     */
    public function getEnvironmentData(): array;

    /**
     * Get database connection information
     *
     * @return string
     */
    public function getDatabaseInfo(): string;

    /**
     * Check if environment info feature is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Get formatted environment info with custom options
     *
     * @param array $options
     * @return string
     */
    public function getCustomEnvironmentInfo(array $options = []): string;
}
