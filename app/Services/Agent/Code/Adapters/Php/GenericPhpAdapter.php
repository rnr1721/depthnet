<?php

namespace App\Services\Agent\Code\Adapters\Php;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * GenericPhpAdapter
 *
 * Fallback adapter for PHP projects that do not match a specific
 * framework. Triggered by composer.json alone.
 */
class GenericPhpAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    public function id(): string
    {
        return 'php';
    }

    public function priority(): int
    {
        return 50;
    }

    public function rootMarkers(): array
    {
        return ['composer.json'];
    }

    public function matches(string $root, callable $executor): bool
    {
        return $this->fileExistsInRoot($executor, $root, 'composer.json');
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('php', '🐘', 'PHP project');
    }

    public function ignoredPaths(): array
    {
        return [
            'vendor',
            '.phpunit.cache',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.php' => '🐘',
        ];
    }
}
