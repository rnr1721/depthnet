<?php

namespace App\Services\Agent\Code\Adapters\Go;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

class GoAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    public function id(): string
    {
        return 'go';
    }

    public function priority(): int
    {
        return 50;
    }

    public function rootMarkers(): array
    {
        return ['go.mod'];
    }

    public function matches(string $root, callable $executor): bool
    {
        return $this->fileExistsInRoot($executor, $root, 'go.mod');
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('go', '🔵', 'Go project');
    }

    public function ignoredPaths(): array
    {
        return ['vendor', '.git'];
    }

    public function fileIcons(): array
    {
        return ['.go' => '🔵'];
    }
}
