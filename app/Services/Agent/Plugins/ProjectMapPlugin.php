<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\Code\WorkspaceTopologyServiceInterface;
use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * ProjectMapPlugin — Automatic workspace map.
 *
 * The [[project_map]] placeholder injects the current workspace structure
 * into the system prompt.
 *
 * Commands:
 *   [projectmap root]~/my-project[/projectmap]   — set project root
 *   [projectmap root auto][/projectmap]          — auto-detect
 *   [projectmap root reset][/projectmap]         — reset to ~
 *   [projectmap root show][/projectmap]          — show current root
 *   [projectmap refresh][/projectmap]            — invalidate map cache
 *   [projectmap expand]storage/framework[/projectmap] — list a subdirectory
 */
class ProjectMapPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    /**
     * Per-preset rendered-map cache.
     *
     * Scoped by preset id so that concurrent agents (e.g. Adalia / Lilia)
     * cannot leak workspace state across each other within a single
     * request lifecycle.
     *
     * @var array<int|string, string>
     */
    private array $mapCache = [];

    public function __construct(
        protected WorkspaceTopologyServiceInterface      $topology,
        protected PresetSandboxServiceInterface          $presetSandboxService,
        protected PlaceholderServiceInterface            $placeholderService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────

    public function getName(): string
    {
        return 'projectmap';
    }

    public function getDescription(array $config = []): string
    {
        return 'Workspace map automatically injected into your prompt via placeholder. '
            . 'Set workspace root with [projectmap root]path[/projectmap] — persists across cycles.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'Workspace Map (automatic):',
            'Your workspace structure is shown in the system message.',
            '',
            'Setting workspace root:',
            '  Set:        [projectmap root]~/my-project[/projectmap]',
            '  Auto:       [projectmap root auto][/projectmap]',
            '  Reset:      [projectmap root reset][/projectmap]',
            '  Show:       [projectmap root show][/projectmap]',
            '',
            'Refining the view:',
            '  Refresh:    [projectmap refresh][/projectmap]',
            '  Expand dir: [projectmap expand]path/to/dir[/projectmap]',
            '',
            'Tip: workspace root persists across cycles. Use "root auto" to let the system pick.',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'projectmap',
            'description' => 'Workspace map is auto-injected into the system message. '
                . 'Set workspace_root to focus on a specific project directory. '
                . 'Default is home (~/). Use "root auto" for automatic detection. '
                . 'Use "expand" to list any single directory in detail.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type' => 'string',
                        'enum' => ['root', 'refresh', 'expand'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'For "root": path, "auto", "reset", or "show". '
                            . 'For "expand": directory path. For "refresh": leave empty.',
                    ],
                ],
                'required' => ['method'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'  => 'checkbox',
                'label' => 'Enable Project Map',
                'value' => false,
            ],
            'max_depth' => [
                'type'  => 'number',
                'label' => 'Max depth',
                'min'   => 1,
                'max'   => 6,
                'value' => 3,
            ],
            'max_files_per_dir' => [
                'type'  => 'number',
                'label' => 'Max files per directory',
                'min'   => 10,
                'max'   => 100,
                'value' => 50,
            ],
            'max_map_length' => [
                'type'  => 'number',
                'label' => 'Max map length (characters)',
                'min'   => 500,
                'max'   => 20000,
                'value' => 3000,
            ],
            'exclude_patterns' => [
                'type'  => 'textarea',
                'label' => 'Extra exclude patterns (one per line)',
                'value' => ".git\n*.log\n.DS_Store",
            ],
            'show_file_icons' => [
                'type'  => 'checkbox',
                'label' => 'Show file type icons',
                'value' => true,
            ],
            'default_root' => [
                'type'        => 'text',
                'label'       => 'Default workspace root',
                'placeholder' => '~/my-project',
                'value'       => '',
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'           => false,
            'max_depth'         => 3,
            'max_files_per_dir' => 50,
            'max_map_length'    => 3000,
            'exclude_patterns'  => ".git\n*.log\n.DS_Store",
            'show_file_icons'   => true,
            'default_root'      => '',
        ];
    }

    public function getSelfClosingTags(): array
    {
        return ['refresh'];
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function collapseOutput(): bool
    {
        return false;
    }

    // ── Shortcode registration ────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'project_map',
            'File tree of the current workspace',
            fn () => $this->renderMap($context),
            $scope
        );
    }

    // ── Commands ──────────────────────────────────────────────────────────

    public function root(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Project Map is disabled.';
        }

        $arg = trim($content);

        if ($arg === 'show' || $arg === '') {
            $root  = $this->topology->getWorkspaceRoot($context->preset, $context->get('default_root', '') ?: null);
            $label = $this->topology->getRootLabel($context->preset, $root);
            return "Current workspace root: {$root} ({$label})";
        }

        if ($arg === 'reset') {
            $this->topology->resetWorkspaceRoot($context->preset);
            $this->invalidateCache($context);
            $root  = $this->topology->getWorkspaceRoot($context->preset, $context->get('default_root', '') ?: null);
            $label = $this->topology->getRootLabel($context->preset, $root);
            return "Workspace root reset to default: {$root} ({$label})";
        }

        if ($arg === 'auto') {
            $detection = $this->topology->autoDetectRoot($context->preset);

            if ($detection->isSuccessful()) {
                $this->topology->setWorkspaceRoot($context->preset, $detection->path);
                $this->invalidateCache($context);
            }

            return "Auto-detected workspace root: {$detection->path} ({$detection->label})\n"
                 . "Detection method: {$detection->method}\n"
                 . ($detection->isSuccessful()
                     ? 'System message will now show this directory.'
                     : 'Set manually: [projectmap root]~/your-project[/projectmap]');
        }

        // Path specified
        $path = $this->topology->normalizePath($context->preset, $arg);

        if (!$this->topology->directoryExists($context->preset, $path)) {
            return "Error: Directory not found: {$path}";
        }

        $this->topology->setWorkspaceRoot($context->preset, $path);
        $this->invalidateCache($context);
        $label = $this->topology->getRootLabel($context->preset, $path);

        return "Workspace root set to: {$path} ({$label})\n"
             . 'System message will now show this directory.';
    }

    public function refresh(string $content, PluginExecutionContext $context): string
    {
        $this->invalidateCache($context);
        return 'Project map cache cleared. Map will rebuild on next cycle.';
    }

    public function expand(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Project Map is disabled.';
        }

        $arg = trim($content);
        if ($arg === '') {
            return 'Error: expand requires a directory path. Example: [projectmap expand]storage/framework[/projectmap]';
        }

        // Relative paths are resolved against the current workspace root.
        $path = str_starts_with($arg, '/') || str_starts_with($arg, '~')
            ? $arg
            : rtrim($this->topology->getWorkspaceRoot($context->preset), '/') . '/' . $arg;

        $path = $this->topology->normalizePath($context->preset, $path);

        if (!$this->topology->directoryExists($context->preset, $path)) {
            return "Error: Directory not found: {$path}";
        }

        $listing = $this->topology->listDirectory($context->preset, $path);

        return $listing !== ''
            ? "Contents of {$path}:\n{$listing}"
            : "Directory {$path} is empty.";
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->root(trim($content) === '' ? 'show' : $content, $context);
    }

    // ── Map rendering ─────────────────────────────────────────────────────

    private function renderMap(PluginExecutionContext $context): string
    {
        $key = (string) $context->preset->getId();

        if (isset($this->mapCache[$key])) {
            return $this->mapCache[$key];
        }

        if (!$context->enabled) {
            return $this->mapCache[$key] = '';
        }

        if (!$this->hasRunningSandbox($context)) {
            return $this->mapCache[$key] = '[PROJECT MAP — no sandbox assigned]';
        }

        $defaultRoot = $context->get('default_root', '') ?: null;

        $root        = $this->topology->getWorkspaceRoot($context->preset, $defaultRoot);
        $rootLabel   = $this->topology->getRootLabel($context->preset, $root);
        $fingerprint = $this->topology->detectProjectType($context->preset, $root);

        $excludes = array_values(array_filter(array_map(
            'trim',
            explode("\n", (string) $context->get('exclude_patterns', ''))
        )));

        $tree = $this->topology->buildTree(
            $context->preset,
            $root,
            (int) $context->get('max_depth', 3),
            (int) $context->get('max_files_per_dir', 50),
            $excludes,
            (bool) $context->get('show_file_icons', true),
            (int) $context->get('max_map_length', 3000),
        );

        $header = $fingerprint->isUnknown()
            ? "[PROJECT MAP — {$rootLabel}]\n"
            : "[PROJECT MAP — {$rootLabel}]\n{$fingerprint->display()}\n";

        $body = trim($tree) === ''
            ? '(workspace is empty)'
            : $tree;

        return $this->mapCache[$key] = "{$header}{$body}\n[END PROJECT MAP]";
    }

    private function invalidateCache(PluginExecutionContext $context): void
    {
        unset($this->mapCache[(string) $context->preset->getId()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function hasRunningSandbox(PluginExecutionContext $context): bool
    {
        $assignment = $this->presetSandboxService->getAssignedSandbox($context->preset->getId());

        return $assignment && ($assignment['sandbox']->status ?? '') === 'running';
    }
}
