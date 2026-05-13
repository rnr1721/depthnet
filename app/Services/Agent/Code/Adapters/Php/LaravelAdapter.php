<?php

namespace App\Services\Agent\Code\Adapters\Php;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * LaravelAdapter
 *
 * Detects Laravel projects via the presence of the `artisan` script
 * alongside composer.json.
 */
class LaravelAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    public function id(): string
    {
        return 'laravel';
    }

    public function priority(): int
    {
        return 110;
    }

    public function rootMarkers(): array
    {
        return ['artisan'];
    }

    public function matches(string $root, callable $executor): bool
    {
        return $this->fileExistsInRoot($executor, $root, 'artisan')
            && $this->fileExistsInRoot($executor, $root, 'composer.json');
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('laravel', '🐘', 'Laravel PHP project');
    }

    public function ignoredPaths(): array
    {
        return [
            'vendor',
            'node_modules',
            'storage/framework',
            'storage/logs',
            'bootstrap/cache',
            'public/build',
            'public/hot',
            '.phpunit.cache',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.blade.php' => '🍃',
            '.php'       => '🐘',
        ];
    }
}
