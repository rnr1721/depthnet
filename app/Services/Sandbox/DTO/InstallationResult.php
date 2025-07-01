<?php

declare(strict_types=1);

namespace App\Services\Sandbox\DTO;

/**
 * Result of package installation
 */
class InstallationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $installedPackages,
        public readonly array $failedPackages,
        public readonly string $output,
        public readonly string $error = ''
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'installed_packages' => $this->installedPackages,
            'failed_packages' => $this->failedPackages,
            'output' => $this->output,
            'error' => $this->error,
        ];
    }
}
