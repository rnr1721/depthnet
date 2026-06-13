<?php

namespace App\Services\Agent\Code\Adapters\Python;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Services\Agent\Code\Adapters\Traits\SandboxFileChecks;
use App\Services\Agent\Code\DTO\ProjectFingerprint;

/**
 * PythonAdapter
 *
 * Detects Python projects via any of the standard project files.
 */
class PythonAdapter implements ProjectAdapterInterface
{
    use SandboxFileChecks;

    private const MARKERS = [
        'pyproject.toml',
        'setup.py',
        'setup.cfg',
        'requirements.txt',
        'Pipfile',
        'poetry.lock',
    ];

    public function id(): string
    {
        return 'python';
    }

    public function priority(): int
    {
        return 50;
    }

    public function rootMarkers(): array
    {
        return self::MARKERS;
    }

    public function matches(string $root, callable $executor): bool
    {
        return $this->anyFileExistsInRoot($executor, $root, self::MARKERS);
    }

    public function fingerprint(): ProjectFingerprint
    {
        return new ProjectFingerprint('python', '🐍', 'Python project');
    }

    public function ignoredPaths(): array
    {
        return [
            '__pycache__',
            '.venv',
            'venv',
            '.tox',
            '.pytest_cache',
            '.mypy_cache',
            '.ruff_cache',
            'dist',
            'build',
            '*.pyc',
            '*.egg-info',
        ];
    }

    public function fileIcons(): array
    {
        return [
            '.py'  => '🐍',
            '.pyi' => '🐍',
        ];
    }
}
