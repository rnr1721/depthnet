<?php

declare(strict_types=1);

namespace App\Services\Sandbox\DTO;

/**
 * Sandbox environment information
 */
class SandboxEnvironment
{
    public function __construct(
        public readonly string $sandboxId,
        public readonly array $languages,
        public readonly array $installedPackages,
        public readonly array $systemInfo,
        public readonly string $workingDirectory = '/home/sandbox-user'
    ) {
    }

    public function hasLanguage(string $language): bool
    {
        return isset($this->languages[$language]);
    }

    public function getLanguageVersion(string $language): ?string
    {
        return $this->languages[$language] ?? null;
    }

    public function getInstalledPackages(string $packageManager): array
    {
        return $this->installedPackages[$packageManager] ?? [];
    }

    public function hasPackage(string $packageManager, string $package): bool
    {
        $packages = $this->getInstalledPackages($packageManager);
        return isset($packages[$package]);
    }

    public function toArray(): array
    {
        return [
            'sandbox_id' => $this->sandboxId,
            'languages' => $this->languages,
            'installed_packages' => $this->installedPackages,
            'system_info' => $this->systemInfo,
            'working_directory' => $this->workingDirectory,
        ];
    }
}
