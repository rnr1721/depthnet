<?php

namespace App\Services\Agent\Code\Adapters\Node;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * NextJsAdapter
 *
 * Detects Next.js projects via the presence of next.config.{js,ts,mjs}.
 */
class NextJsAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    private const CONFIG_FILES = [
        'next.config.js',
        'next.config.ts',
        'next.config.mjs',
    ];

    public function id(): string
    {
        return 'nextjs';
    }

    public function priority(): int
    {
        return 110;
    }

    public function rootMarkers(): array
    {
        return ['package.json'];
    }

    public function matches(string $root, callable $executor): bool
    {
        if (!$this->fileExistsInRoot($executor, $root, 'package.json')) {
            return false;
        }

        return $this->anyFileExistsInRoot($executor, $root, self::CONFIG_FILES);
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('nextjs', '⚛️', 'Next.js project');
    }

    public function ignoredPaths(): array
    {
        return [
            'node_modules',
            '.next',
            '.turbo',
            '.vercel',
            'dist',
            'build',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.tsx' => '⚛️',
            '.jsx' => '⚛️',
            '.ts'  => '🔷',
            '.js'  => '🟨',
        ];
    }
}
