<?php

namespace App\Services\Agent\Code\Adapters\Traits;

/**
 * SandboxFileChecks
 *
 * Small helpers for project adapters that need to check
 * file presence inside the sandbox via a callable executor.
 */
trait SandboxFileChecks
{
    /**
     * Checks whether a single file exists at $root/$relativePath.
     */
    protected function fileExistsInRoot(callable $executor, string $root, string $relativePath): bool
    {
        $full = $root . '/' . ltrim($relativePath, '/');
        $cmd  = 'test -f ' . escapeshellarg($full) . ' && echo yes || echo no';

        return trim($executor($cmd, 2)) === 'yes';
    }

    /**
     * Checks whether ANY of the given relative paths exists at $root.
     *
     * Uses a single grouped shell command to minimize round-trips.
     *
     * @param array<int, string> $relativePaths
     */
    protected function anyFileExistsInRoot(callable $executor, string $root, array $relativePaths): bool
    {
        if (empty($relativePaths)) {
            return false;
        }

        $tests = [];
        foreach ($relativePaths as $rel) {
            $full    = $root . '/' . ltrim($rel, '/');
            $tests[] = 'test -f ' . escapeshellarg($full);
        }

        $cmd = '( ' . implode(' || ', $tests) . ' ) && echo yes || echo no';

        return trim($executor($cmd, 2)) === 'yes';
    }
}
