<?php

namespace App\Services\Agent\Code;

use App\Contracts\Agent\Code\ProjectAdapterInterface;
use App\Contracts\Agent\Code\ProjectAdapterRegistryInterface;
use App\Contracts\Agent\Code\WorkspaceTopologyServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Models\AiPreset;
use App\Services\Agent\Code\DTO\ProjectFingerprint;
use App\Services\Agent\Code\DTO\WorkspaceRootDetection;
use Psr\Log\LoggerInterface;

/**
 * WorkspaceTopologyService — workspace mapping business logic.
 *
 * Language- and framework-specific behavior is delegated to
 * ProjectAdapterInterface implementations resolved through the registry.
 */
class WorkspaceTopologyService implements WorkspaceTopologyServiceInterface
{
    private const META_NAMESPACE = 'projectmap';
    private const META_ROOT      = 'workspace_root';

    /** Default home directory inside the sandbox container. */
    public const SANDBOX_HOME = '/home/sandbox-user';

    /** Soft cap on the rendered tree size (characters). */
    public const DEFAULT_MAX_MAP_LENGTH = 3000;

    /** Maximum depth for the deep-scan root detection step. */
    private const DEEP_SCAN_MAX_DEPTH = 3;

    /**
     * Hidden files always allowed through the tree filter.
     */
    public const KEEP_HIDDEN = [
        '.gitignore',
        '.editorconfig',
        '.env.example',
        '.dockerignore',
        '.eslintrc',
        '.eslintrc.js',
        '.eslintrc.json',
        '.prettierrc',
        '.prettierrc.json',
    ];

    public function __construct(
        protected SandboxManagerInterface         $sandboxManager,
        protected PresetSandboxServiceInterface   $presetSandboxService,
        protected PluginMetadataServiceInterface  $pluginMetadata,
        protected ProjectAdapterRegistryInterface $adapterRegistry,
        protected LoggerInterface                 $logger,
    ) {
    }

    // ── Workspace Root ────────────────────────────────────────────────────

    public function getWorkspaceRoot(AiPreset $preset, ?string $defaultRoot = null): string
    {
        $saved = $this->pluginMetadata->get($preset, self::META_NAMESPACE, self::META_ROOT);

        if (is_string($saved) && $saved !== '') {
            return $saved;
        }

        if ($defaultRoot !== null && $defaultRoot !== '') {
            return $this->normalizePath($preset, $defaultRoot);
        }

        return self::SANDBOX_HOME;
    }

    public function setWorkspaceRoot(AiPreset $preset, string $path): void
    {
        $this->pluginMetadata->set($preset, self::META_NAMESPACE, self::META_ROOT, $path);
    }

    public function resetWorkspaceRoot(AiPreset $preset): void
    {
        $this->pluginMetadata->remove($preset, self::META_NAMESPACE, self::META_ROOT);
    }

    public function normalizePath(AiPreset $preset, string $path): string
    {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return $path;
        }

        $path = trim($path);

        // Expand "~/" and bare "~"
        if (str_starts_with($path, '~/')) {
            $path = self::SANDBOX_HOME . '/' . substr($path, 2);
        } elseif ($path === '~') {
            $path = self::SANDBOX_HOME;
        }

        // realpath -m (GNU) → realpath (POSIX) → readlink -f → original
        $resolved = $this->exec(
            $sandboxId,
            'realpath -m ' . escapeshellarg($path) . ' 2>/dev/null || '
            . 'realpath ' . escapeshellarg($path) . ' 2>/dev/null || '
            . 'readlink -f ' . escapeshellarg($path) . ' 2>/dev/null || '
            . 'echo ' . escapeshellarg($path),
            3
        );

        $resolved = preg_replace('#/+#', '/', $resolved);

        if ($resolved !== '/' && str_ends_with($resolved, '/')) {
            $resolved = rtrim($resolved, '/');
        }

        return $resolved === '' ? $path : $resolved;
    }

    public function getRootLabel(AiPreset $preset, string $root): string
    {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return basename($root) ?: $root;
        }

        // Try git remote name first
        $gitName = $this->exec(
            $sandboxId,
            'cd ' . escapeshellarg($root) . ' 2>/dev/null && '
            . 'git remote get-url origin 2>/dev/null | sed "s|.*/||; s|\.git$||" || echo ""',
            2
        );

        if ($gitName !== '') {
            return $gitName;
        }

        if ($root === self::SANDBOX_HOME) {
            return '~';
        }

        if (str_starts_with($root, self::SANDBOX_HOME . '/')) {
            return '~/' . substr($root, strlen(self::SANDBOX_HOME) + 1);
        }

        return basename($root) ?: $root;
    }

    public function directoryExists(AiPreset $preset, string $path): bool
    {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return false;
        }

        return $this->exec(
            $sandboxId,
            'test -d ' . escapeshellarg($path) . ' && echo yes || echo no',
            3
        ) === 'yes';
    }

    // ── Auto-detect ───────────────────────────────────────────────────────

    public function autoDetectRoot(AiPreset $preset): WorkspaceRootDetection
    {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return new WorkspaceRootDetection(self::SANDBOX_HOME, 'fallback', '~');
        }

        // 1. Git root
        $gitRoot = $this->exec(
            $sandboxId,
            'cd ' . escapeshellarg(self::SANDBOX_HOME)
            . ' && git rev-parse --show-toplevel 2>/dev/null || echo ""',
            3
        );

        if ($gitRoot !== '') {
            $path = $this->normalizePath($preset, $gitRoot);
            return new WorkspaceRootDetection($path, 'git', $this->getRootLabel($preset, $path));
        }

        $markers = $this->adapterRegistry->allRootMarkers();

        // 2. Markers in the home directory
        foreach ($markers as $marker) {
            $full = self::SANDBOX_HOME . '/' . $marker;
            $exists = $this->exec(
                $sandboxId,
                'test -f ' . escapeshellarg($full) . ' && echo yes || echo no',
                2
            );

            if ($exists === 'yes') {
                return new WorkspaceRootDetection(
                    self::SANDBOX_HOME,
                    "marker:{$marker}",
                    $this->getRootLabel($preset, self::SANDBOX_HOME)
                );
            }
        }

        // 3. Deep scan up to DEEP_SCAN_MAX_DEPTH
        $deepFound = $this->deepScanForMarkers($sandboxId, $markers);
        if ($deepFound !== null) {
            $path = $this->normalizePath($preset, $deepFound);
            return new WorkspaceRootDetection($path, 'deep-scan', $this->getRootLabel($preset, $path));
        }

        // 4. Fallback
        return new WorkspaceRootDetection(self::SANDBOX_HOME, 'fallback', '~');
    }

    /**
     * @param array<int, string> $markers
     */
    private function deepScanForMarkers(string $sandboxId, array $markers): ?string
    {
        if (empty($markers)) {
            return null;
        }

        $conditions = [];
        foreach ($markers as $marker) {
            $conditions[] = '-name ' . escapeshellarg($marker);
        }
        $pattern = implode(' -o ', $conditions);

        $result = $this->exec(
            $sandboxId,
            'find ' . escapeshellarg(self::SANDBOX_HOME)
            . ' -maxdepth ' . self::DEEP_SCAN_MAX_DEPTH
            . ' -type f \( ' . $pattern . ' \) 2>/dev/null '
            . '| head -1 | xargs -r dirname 2>/dev/null || echo ""',
            5
        );

        return $result !== '' ? $result : null;
    }

    // ── Project Fingerprint ───────────────────────────────────────────────

    public function detectProjectType(AiPreset $preset, string $root): ProjectFingerprint
    {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return ProjectFingerprint::unknown();
        }

        return $this->adapterRegistry->fingerprint($root, $this->makeExecutor($sandboxId));
    }

    /**
     * Returns the adapter matching the given root, or null if none does.
     */
    private function resolveAdapter(string $sandboxId, string $root): ?ProjectAdapterInterface
    {
        return $this->adapterRegistry->detect($root, $this->makeExecutor($sandboxId));
    }

    // ── Build Tree ────────────────────────────────────────────────────────

    public function buildTree(
        AiPreset $preset,
        string $root,
        int $maxDepth = 3,
        int $maxFiles = 50,
        array $excludePatterns = [],
        bool $showIcons = true,
        ?int $maxLength = null
    ): string {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return '';
        }

        $adapter = $this->resolveAdapter($sandboxId, $root);

        // Merge adapter-suggested excludes with user excludes (user wins on duplicates).
        $adapterExcludes = $adapter !== null ? $adapter->ignoredPaths() : [];
        $excludes = array_values(array_unique(array_merge(
            $adapterExcludes,
            array_filter(array_map('trim', $excludePatterns))
        )));

        $excludeArgs   = $this->buildExcludeArgs($excludes);
        $hiddenExclude = $this->buildHiddenExclude();

        $cmd = sprintf(
            'cd %s && '
            . '(find . -maxdepth %d -type d %s %s 2>/dev/null | sed "s|^|D |" ; '
            . 'find . -maxdepth %d -type f %s %s 2>/dev/null | sed "s|^|F |") '
            . '| sort | head -500',
            escapeshellarg($root),
            $maxDepth,
            $excludeArgs,
            $hiddenExclude,
            $maxDepth,
            $excludeArgs,
            $hiddenExclude
        );

        $output = $this->exec($sandboxId, $cmd, 5);
        if ($output === '') {
            return '';
        }

        $entries = $this->parseFindEntries($output);
        if (empty($entries)) {
            return '';
        }

        $iconResolver = $this->makeIconResolver($adapter, $showIcons);
        $tree         = $this->formatAsTree($entries, $iconResolver, $maxFiles);

        return $this->trimToMaxLength($tree, $maxLength ?? self::DEFAULT_MAX_MAP_LENGTH);
    }

    public function listDirectory(AiPreset $preset, string $path, int $maxItems = 100): string
    {
        $sandboxId = $this->getSandboxId($preset);
        if ($sandboxId === null) {
            return '';
        }

        $cmd = 'cd ' . escapeshellarg($path) . ' 2>/dev/null && '
             . '(find . -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sed "s|^./|D |" ; '
             . ' find . -mindepth 1 -maxdepth 1 -type f 2>/dev/null | sed "s|^./|F |") '
             . '| sort | head -' . max(1, $maxItems);

        $output = $this->exec($sandboxId, $cmd, 3);
        if ($output === '') {
            return '';
        }

        $adapter      = $this->resolveAdapter($sandboxId, $path);
        $iconResolver = $this->makeIconResolver($adapter, true);

        $lines = [];
        foreach (explode("\n", $output) as $line) {
            if (strlen($line) < 3) {
                continue;
            }
            $type = $line[0];
            $name = trim(substr($line, 2));
            if ($name === '' || $name === '.') {
                continue;
            }
            $lines[] = $type === 'D'
                ? '📁 ' . $name . '/'
                : $iconResolver($name) . $name;
        }

        return implode("\n", $lines);
    }

    // ── Private: tree building ────────────────────────────────────────────

    /**
     * @return array<int, array{type: string, path: string}>
     */
    private function parseFindEntries(string $output): array
    {
        $entries = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (strlen($line) < 3) {
                continue;
            }
            $type = $line[0];
            if ($type !== 'D' && $type !== 'F') {
                continue;
            }
            $path = trim(substr($line, 2));
            if ($path === '' || $path === '.') {
                continue;
            }
            $entries[] = ['type' => $type, 'path' => $path];
        }
        return $entries;
    }

    /**
     * @param array<int, array{type: string, path: string}> $entries
     */
    private function formatAsTree(array $entries, callable $iconResolver, int $maxFiles): string
    {
        $tree = [];

        foreach ($entries as $entry) {
            $parts = array_values(array_filter(
                explode('/', $entry['path']),
                static fn ($p) => $p !== '.' && $p !== ''
            ));

            if (empty($parts)) {
                continue;
            }

            $current   = &$tree;
            $lastIndex = count($parts) - 1;

            foreach ($parts as $i => $part) {
                $isLast = ($i === $lastIndex);

                if ($isLast) {
                    if ($entry['type'] === 'D') {
                        if (!isset($current[$part]) || !is_array($current[$part])) {
                            $current[$part] = [];
                        }
                    } else {
                        $current['__files__'][] = $part;
                    }
                } else {
                    if (!isset($current[$part]) || !is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
            unset($current);
        }

        return $this->renderTree($tree, '', $iconResolver, $maxFiles);
    }

    private function renderTree(array $node, string $indent, callable $iconResolver, int $maxFiles): string
    {
        $lines = [];

        $files = $node['__files__'] ?? [];
        unset($node['__files__']);

        $dirs = [];
        foreach ($node as $name => $children) {
            if (is_array($children)) {
                $dirs[$name] = $children;
            }
        }
        ksort($dirs);

        foreach ($dirs as $name => $children) {
            $lines[] = $indent . '📁 ' . $name . '/';
            $child = $this->renderTree($children, $indent . '  ', $iconResolver, $maxFiles);
            if ($child !== '') {
                $lines[] = $child;
            }
        }

        sort($files);

        $shownFiles = array_slice($files, 0, $maxFiles);
        $remaining  = count($files) - $maxFiles;

        foreach ($shownFiles as $file) {
            $lines[] = $indent . $iconResolver($file) . $file;
        }

        if ($remaining > 0) {
            $lines[] = $indent . "... and {$remaining} more files";
        }

        return implode("\n", $lines);
    }

    // ── Private: icon resolution ──────────────────────────────────────────

    /**
     * Builds an icon-resolver closure for the active adapter.
     *
     * Returned closure accepts a filename and returns "<icon> "
     * (with trailing space), or "• " when icons are disabled,
     * or "" when no specific icon applies but icons are on.
     */
    private function makeIconResolver(?ProjectAdapterInterface $adapter, bool $showIcons): callable
    {
        if (!$showIcons) {
            return static fn (string $_file) => '• ';
        }

        $adapterIcons = $adapter !== null ? $adapter->fileIcons() : [];

        return function (string $filename) use ($adapterIcons): string {
            // Adapter icons take precedence — match longest suffix first.
            uksort($adapterIcons, static fn ($a, $b) => strlen($b) <=> strlen($a));

            foreach ($adapterIcons as $suffix => $icon) {
                if (str_ends_with($filename, $suffix)) {
                    return $icon . ' ';
                }
            }

            return $this->defaultFileIcon($filename);
        };
    }

    /**
     * Generic icon table for common file types not handled by an adapter.
     */
    private function defaultFileIcon(string $filename): string
    {
        return match (true) {
            str_ends_with($filename, '.json')   => '📋 ',
            str_ends_with($filename, '.yaml'),
            str_ends_with($filename, '.yml')    => '⚙️ ',
            str_ends_with($filename, '.toml')   => '⚙️ ',
            str_ends_with($filename, '.md')     => '📝 ',
            str_ends_with($filename, '.txt')    => '📄 ',
            str_ends_with($filename, '.sql')    => '🗄️ ',
            str_ends_with($filename, '.css'),
            str_ends_with($filename, '.scss')   => '🎨 ',
            str_ends_with($filename, '.html')   => '🌐 ',
            str_ends_with($filename, '.sh'),
            str_ends_with($filename, '.bash')   => '⚡ ',
            str_ends_with($filename, '.xml')    => '📰 ',
            str_ends_with($filename, '.lock')   => '🔒 ',
            $filename === 'Dockerfile'          => '🐳 ',
            $filename === 'Makefile'            => '🔨 ',
            default                             => '• ',
        };
    }

    // ── Private: exclude patterns ─────────────────────────────────────────

    /**
     * Builds `-not -path` / `-not -name` arguments for find.
     *
     * @param array<int, string> $patterns
     */
    private function buildExcludeArgs(array $patterns): string
    {
        $args = '';

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '*')) {
                $args .= ' -not -name ' . escapeshellarg($pattern);
            } else {
                $args .= ' -not -path ' . escapeshellarg("*/{$pattern}/*")
                       . ' -not -path ' . escapeshellarg("./{$pattern}")
                       . ' -not -path ' . escapeshellarg("./{$pattern}/*");
            }
        }

        return $args;
    }

    /**
     * Excludes hidden files except those in KEEP_HIDDEN.
     *
     * De Morgan form: ( not hidden ) OR ( name in whitelist ).
     */
    private function buildHiddenExclude(): string
    {
        $whitelist = [];
        foreach (self::KEEP_HIDDEN as $name) {
            $whitelist[] = '-name ' . escapeshellarg($name);
        }

        return ' \( -not -path "*/.*" -o ' . implode(' -o ', $whitelist) . ' \)';
    }

    // ── Private: output trimming ──────────────────────────────────────────

    private function trimToMaxLength(string $tree, int $maxLength): string
    {
        if (mb_strlen($tree) <= $maxLength) {
            return $tree;
        }

        $trimmed     = mb_substr($tree, 0, $maxLength);
        $lastNewline = mb_strrpos($trimmed, "\n");

        if ($lastNewline !== false && $lastNewline > $maxLength * 0.8) {
            $trimmed = mb_substr($trimmed, 0, $lastNewline);
        }

        return $trimmed . "\n... (truncated, use [projectmap expand]path[/projectmap] to drill down)";
    }

    // ── Sandbox helpers ──────────────────────────────────────────────────

    /**
     * Returns a closure that runs commands against a specific sandbox.
     */
    private function makeExecutor(string $sandboxId): callable
    {
        return fn (string $cmd, int $timeout = 5) => $this->exec($sandboxId, $cmd, $timeout);
    }

    /**
     * Executes a command inside the sandbox and returns trimmed stdout.
     */
    private function exec(string $sandboxId, string $cmd, int $timeout = 5): string
    {
        try {
            $result = $this->sandboxManager->executeCommand($sandboxId, $cmd, 'sandbox-user', $timeout);
            return trim($result->output ?: '');
        } catch (\Throwable $e) {
            $this->logger->error('WorkspaceTopologyService::exec error', [
                'sandbox_id' => $sandboxId,
                'command'    => substr($cmd, 0, 200),
                'error'      => $e->getMessage(),
            ]);
            return '';
        }
    }

    private function getSandboxId(AiPreset $preset): ?string
    {
        $assignment = $this->presetSandboxService->getAssignedSandbox($preset->getId());

        if (!$assignment || ($assignment['sandbox']->status ?? '') !== 'running') {
            return null;
        }

        return $assignment['sandbox_id'];
    }
}
