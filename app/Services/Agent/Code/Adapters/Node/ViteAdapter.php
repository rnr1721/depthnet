<?php

namespace App\Services\Agent\Code\Adapters\Node;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * ViteAdapter
 *
 * Detects Vite-based projects (React, Vue, Svelte, vanilla TS, etc.).
 */
class ViteAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    private const CONFIG_FILES = [
        'vite.config.js',
        'vite.config.ts',
        'vite.config.mjs',
        'vite.config.mts',
    ];

    public function id(): string
    {
        return 'vite';
    }

    public function priority(): int
    {
        return 100;
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
        return new ProjectFingerprint('vite', '⚡', 'Vite project');
    }

    public function ignoredPaths(): array
    {
        return [
            'node_modules',
            'dist',
            '.vite',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.vue'    => '💚',
            '.svelte' => '🧡',
            '.tsx'    => '⚛️',
            '.jsx'    => '⚛️',
            '.ts'     => '🔷',
            '.js'     => '🟨',
        ];
    }
}
