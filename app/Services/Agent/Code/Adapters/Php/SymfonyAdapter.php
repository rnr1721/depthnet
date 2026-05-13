<?php

namespace App\Services\Agent\Code\Adapters\Php;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * SymfonyAdapter
 *
 * Detects Symfony projects via the `bin/console` script alongside
 * composer.json.
 */
class SymfonyAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    public function id(): string
    {
        return 'symfony';
    }

    public function priority(): int
    {
        return 105;
    }

    public function rootMarkers(): array
    {
        return ['composer.json'];
    }

    public function matches(string $root, callable $executor): bool
    {
        return $this->fileExistsInRoot($executor, $root, 'bin/console')
            && $this->fileExistsInRoot($executor, $root, 'composer.json');
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('symfony', '🎼', 'Symfony PHP project');
    }

    public function ignoredPaths(): array
    {
        return [
            'vendor',
            'node_modules',
            'var/cache',
            'var/log',
            'public/build',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.twig' => '🌿',
            '.php'  => '🐘',
        ];
    }
}
