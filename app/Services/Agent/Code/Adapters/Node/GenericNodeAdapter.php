<?php

namespace App\Services\Agent\Code\Adapters\Node;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\DTO\ProjectFingerprint;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;

/**
 * GenericNodeAdapter
 *
 * Fallback adapter for Node.js projects without a recognized
 * meta-framework. Triggered by package.json alone.
 */
class GenericNodeAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    public function id(): string
    {
        return 'node';
    }

    public function priority(): int
    {
        return 50;
    }

    public function rootMarkers(): array
    {
        return ['package.json'];
    }

    public function matches(string $root, callable $executor): bool
    {
        return $this->fileExistsInRoot($executor, $root, 'package.json');
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('node', '🟢', 'Node.js project');
    }

    public function ignoredPaths(): array
    {
        return [
            'node_modules',
            'dist',
            'build',
            '.cache',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.tsx' => '⚛️',
            '.jsx' => '⚛️',
            '.ts'  => '🔷',
            '.mjs' => '🟨',
            '.cjs' => '🟨',
            '.js'  => '🟨',
        ];
    }
}
