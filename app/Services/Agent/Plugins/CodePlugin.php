<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * CodePlugin
 *
 * Gives the agent structured access to the sandbox filesystem:
 * navigate directory trees, read files (with line ranges or function context),
 * search by text, and apply targeted edits (replace / patch).
 *
 * Intentionally separate from DocumentManagerPlugin:
 *   - documents = uploaded knowledge base, semantic RAG search
 *   - code      = sandbox workspace, structural file operations
 *
 * Requires a sandbox assigned to the preset.
 * All paths are relative to sandbox-user home (~/).
 */
class CodePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected PresetSandboxServiceInterface $presetSandboxService,
        protected SandboxManagerInterface $sandboxManager,
        protected LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return 'code';
    }

    public function getDescription(array $config = []): string
    {
        return 'Navigate and edit files in the sandbox workspace (requires sandbox assigned to preset). '
            . 'All paths are relative to sandbox home (~/).';
    }

    public function getInstructions(array $config = []): array
    {

        $unified = $config['unified_edit'] ?? true;

        $editInstructions = $unified ? [
            'Edit file (replace format): [code edit]path: ...' . "\n" . 'search: ...' . "\n" . 'replace: ...[/code]',
            'Edit file (diff format): [code edit]--- a/...' . "\n" . '+++ b/...[/code]',
            'Create or edit file: [code edit]path: ...' . "\n" . 'replace: ...' . "\n" . 'create:true[/code]',
            'Write file: [code write]path: file.php' . "\n" . 'content: <?php ...[/code]',
            'Edit or overwrite file: [code edit]path: file.php' . "\n" . 'replace: full content[/code]',
            '⚠️ INDENTATION: For Python, YAML, or any indentation-sensitive code — copy leading whitespace from search to replace exactly.',
            'Batch edit multiple files: [code batch]' . "\n"
                . '1. path: app/Models/User.php | search: getStatus | replace: fetchStatus | limit: 1' . "\n"
                . '2. path: app/Services/UserService.php | search: getStatus | replace: fetchStatus' . "\n"
                . '3. path: resources/js/components/UserCard.vue | search: getStatus | replace: fetchStatus' . "\n"
                . '[/code]',
        ] : [
            'Replace in file: [code replace]path: ...' . "\n" . 'search: ...' . "\n" . 'replace: ...[/code]',
            'Apply patch: [code patch]--- a/...' . "\n" . '+++ b/...[/code]',
             '⚠️ INDENTATION: For Python, YAML, or any indentation-sensitive code — copy leading whitespace from search to replace exactly.'
        ];

        return array_merge([
            // Navigation
            'Show directory tree: [code tree][/code]',
            'Tree of specific path: [code tree]app/Services[/code]',
            'File/directory info: [code info]app/Services/UserService.php[/code]',

            // Reading
            'Read full file: [code read]app/Services/UserService.php[/code]',
            'Read line range: [code read]app/Services/UserService.php | lines:1-50[/code]',
            'Read around function: [code read]app/Services/UserService.php | around:calculatePrice[/code]',

            // Search
            'Search text in workspace: [code search]calculatePrice[/code]',
            'Search in specific path: [code search]calculatePrice | path:app/Services[/code]',
        ], $editInstructions);
    }

    public function getToolSchema(array $config = []): array
    {

        $editMethods = ($config['unified_edit'] ?? true)
            ? ['edit', 'batch']
            : ['replace', 'patch'];

        return [
            'name'        => 'code',
            'description' => 'Navigate and edit files in the sandbox workspace. '
                . 'All paths relative to sandbox home (~/). '
                . 'Use for structural file operations on the current project. '
                . 'Use "documents" plugin instead for semantic search over uploaded knowledge files.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method'  => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum' => array_merge(['tree', 'info', 'read', 'search', 'write'], $editMethods),
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'Argument depends on method:',
                            '',
                            '• tree: optional path (empty = current directory).',
                            '• info: file or directory path.',
                            '• read: "path" or "path | lines:N-M" or "path | around:functionName".',
                            '• search: "query" or "query | path:dir".',
                            '',
                            '• edit: automatically detects format —',
                            '  supports "create:true" to create file if it does not exist.',
                            '  if file does not exist and no search is provided, "replace" becomes full file content.',
                            '  either key-value: "path: ...\nsearch: ...\nreplace: ...\n[limit: 1]"',
                            '  or unified diff: "--- a/...\n+++ b/...\n@@ -l,c +l,c @@\n- old\n+ new".',
                            '',
                            '• batch: numbered list of operations, each on a new line.',
                            '  Format per line: "path: file.php | search: old | replace: new [| limit: 1] [| create: true]"',
                            '  Lines starting with # are comments. Operations execute sequentially.',
                            '  If an operation fails, remaining operations continue.',
                            '',
                            '⚠️ For indentation-sensitive languages (Python, YAML, etc.): '
                            . 'preserve exact leading whitespace from search in replace. '
                            . 'Example: if search has 8 spaces → replace must keep 8 spaces.',
                            '',
                            'Tip: for edit, both formats work — the plugin will auto-detect which to use.',
                        ]),
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
                'type'        => 'checkbox',
                'label'       => 'Enable Code Plugin',
                'description' => 'Allow agent to navigate and edit sandbox filesystem',
                'required'    => false,
            ],
            'max_read_lines' => [
                'type'        => 'number',
                'label'       => 'Max lines to read',
                'description' => 'Maximum lines returned by read command (prevents huge outputs)',
                'min'         => 50,
                'max'         => 2000,
                'value'       => 500,
                'required'    => false,
            ],
            'max_tree_depth' => [
                'type'        => 'number',
                'label'       => 'Max tree depth',
                'description' => 'Maximum directory depth for tree command',
                'min'         => 1,
                'max'         => 10,
                'value'       => 4,
                'required'    => false,
            ],
            'around_context_lines' => [
                'type'        => 'number',
                'label'       => 'Around context lines',
                'description' => 'Lines of context above/below function for "around:" read',
                'min'         => 5,
                'max'         => 100,
                'value'       => 20,
                'required'    => false,
            ],
            'unified_edit' => [
                'type'        => 'checkbox',
                'label'       => 'Use unified edit command',
                'description' => 'Expose single "edit" method instead of separate "replace" and "patch". '
                    . 'The agent auto-detects format (key-value or unified diff).',
                'value'       => true,
                'required'    => false,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'              => false,
            'max_read_lines'       => 500,
            'max_tree_depth'       => 4,
            'around_context_lines' => 20,
            'unified_edit' => true,
        ];
    }

    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function collapseOutput(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['tree'];
    }

    // -------------------------------------------------------------------------
    // Default — proxies to tree
    // -------------------------------------------------------------------------

    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->tree($content ?: '.', $context);
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    /**
     * [code tree]optional/path[/code]
     */
    public function tree(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $path = $this->normalizePath($content) ?: '.';
        $depth = (int) $context->get('max_tree_depth', 4);

        $cmd = sprintf(
            'find %s -maxdepth %d | sort | sed "s|[^/]*/|  |g"',
            escapeshellarg($path),
            $depth
        );

        return $this->exec($context, $cmd, "Tree: {$path}");
    }

    /**
     * [code info]path/to/file[/code]
     */
    public function info(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $path = $this->normalizePath($content);
        if (!$path) {
            return 'Error: path required.';
        }

        $cmd = sprintf(
            'stat %s 2>&1 && echo "---" && (file %s 2>/dev/null || true) && echo "---" && (wc -l %s 2>/dev/null || true)',
            escapeshellarg($path),
            escapeshellarg($path),
            escapeshellarg($path),
        );

        return $this->exec($context, $cmd, "Info: {$path}");
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * [code read]path[/code]
     * [code read]path | lines:1-50[/code]
     * [code read]path | around:functionName[/code]
     */
    public function read(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $content = $this->normalizePath($content);
        [$path, $modifier] = $this->splitModifier($content);

        if (!$path) {
            return 'Error: path required.';
        }

        if ($this->pathType($context, $path) !== 'FILE') {
            return "Error: file not found: {$path}";
        }

        $maxLines = (int) $context->get('max_read_lines', 500);

        if ($modifier && str_starts_with($modifier, 'lines:')) {
            // lines:N-M
            $range = substr($modifier, 6);
            [$from, $to] = array_map('intval', explode('-', $range, 2));
            $to = min($to, $from + $maxLines - 1);
            $cmd = sprintf('sed -n "%d,%dp" %s 2>&1', $from, $to, escapeshellarg($path));
            return $this->exec($context, $cmd, "{$path} lines {$from}-{$to}");
        }

        if ($modifier && str_starts_with($modifier, 'around:')) {
            // around:functionName
            $symbol  = substr($modifier, 7);
            $context_lines = (int) $context->get('around_context_lines', 20);
            // Find line number of first match, then extract context
            $cmd = sprintf(
                'line=$(grep -n %s %s 2>/dev/null | head -1 | cut -d: -f1); '
                . 'if [ -n "$line" ]; then '
                . 'from=$((line > %d ? line - %d : 1)); '
                . 'to=$((line + %d)); '
                . 'echo "Context around line $line:"; '
                . 'sed -n "${from},${to}p" %s; '
                . 'else echo "Symbol not found: %s"; fi',
                escapeshellarg($symbol),
                escapeshellarg($path),
                $context_lines,
                $context_lines,
                $context_lines,
                escapeshellarg($path),
                $symbol
            );
            return $this->exec($context, $cmd, "{$path} around:{$symbol}");
        }

        // Full file — capped at maxLines
        $cmd = sprintf('head -n %d %s 2>&1', $maxLines, escapeshellarg($path));
        $result = $this->exec($context, $cmd, "Read: {$path}");

        // Warn if file was truncated
        $lineCount = $this->execRaw($context, sprintf('wc -l < %s 2>/dev/null', escapeshellarg($path)));
        $total = (int) trim($lineCount);
        if ($total > $maxLines) {
            $result .= "\n\n[Truncated: showing {$maxLines} of {$total} lines. Use lines:N-M to read more.]";
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * [code search]query[/code]
     * [code search]query | path:app/Services[/code]
     */
    public function search(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $content = $this->normalizePath($content);
        [$query, $modifier] = $this->splitModifier($content);

        if (!$query) {
            return 'Error: search query required.';
        }

        $searchPath = '.';
        if ($modifier && str_starts_with($modifier, 'path:')) {
            $searchPath = trim(substr($modifier, 5));
        }

        if ($this->pathType($context, $searchPath) === 'NO') {
            return "Error: path not found: {$searchPath}";
        }

        // grep: recursive, with line numbers, binary files skipped
        $cmd = sprintf(
            'tmp=$(mktemp) && ' .
            'grep -rn --binary-files=without-match %s %s 2>/dev/null | tee "$tmp" | head -50; ' .
            'echo "---"; echo "Total matches: $(wc -l < "$tmp")"; ' .
            'rm -f "$tmp"',
            escapeshellarg($query),
            escapeshellarg($searchPath),
        );

        return $this->exec($context, $cmd, "Search: \"{$query}\" in {$searchPath}");
    }

    // -------------------------------------------------------------------------
    // Editing
    // -------------------------------------------------------------------------

    /**
     * [code replace]
     * path: app/Services/UserService.php
     * search: return $total;
     * replace: return round($total, 2);
     * [/code]
     */
    public function replace(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $params = $this->parseKeyValue($content);

        $path    = $params['path']    ?? null;
        $path = $this->normalizePath($path);

        $fileSize = (int) trim($this->execRaw($context, sprintf('stat -c%%s %s 2>/dev/null || echo 0', escapeshellarg($path))));
        if ($fileSize > 1024 * 1024) { // 1MB
            return "Error: file too large ({$fileSize} bytes). Use [terminal] for large file editing.";
        }

        $search  = $params['search']  ?? null;
        if ($search === '') {
            $search = null;
        }
        $replace = $params['replace'] ?? null;
        $limit   = isset($params['limit']) ? (int)$params['limit'] : null;
        $autoCreate = filter_var($params['create'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$path || $replace === null) {
            return 'Error: replace requires at least path and replace fields.';
        }

        $type = $this->pathType($context, $path);

        if ($type === 'DIR') {
            return "Error: {$path} is a directory, not a file.";
        }

        if ($type === 'NO') {
            if ($autoCreate) {
                return $this->write(sprintf(
                    "path: %s\ncontent: %s",
                    $path,
                    $replace
                ), $context);
            } else {
                return implode("\n", [
                    "Error: file not found: {$path}",
                    "To create a new file use the 'write' method with path and content,",
                    "or use 'edit' method with 'create:true' parameter.",
                ]);
            }
        } else {
            $current = $this->execRaw($context, sprintf('cat %s 2>&1', escapeshellarg($path)));
        }

        if ($search === null) {
            $updated = $replace;
            $applied = 1;
            $diff = "(full file overwrite)";
        } else {
            if (!str_contains($current, $search)) {
                return implode("\n", [
                    "Error: search string not found in {$path}",
                    "To create or overwrite the file use the 'write' method,",
                    "or use 'edit' with 'create:true' and no search field.",
                ]);
            }

            $count = substr_count($current, $search);

            if ($limit === null && $count > 1) {
                return "Warning: {$count} matches found. Add 'limit: 1' or be more specific.";
            }

            if ($limit === 1) {
                $pos = strpos($current, $search);
                $updated = substr($current, 0, $pos)
                    . $replace
                    . substr($current, $pos + strlen($search));
                $applied = 1;
            } else {
                $updated = str_replace($search, $replace, $current);
                $applied = $count;
            }
        }

        if ($current === $updated) {
            return "No changes (content identical after replace).";
        }

        $diff = $this->getUnifiedDiff($context, $path, $current, $updated);

        $encoded = base64_encode($updated);
        $writeCmd = sprintf(
            'echo %s | base64 -d > %s && echo "OK"',
            escapeshellarg($encoded),
            escapeshellarg($path)
        );

        $result = trim($this->execRaw($context, $writeCmd));

        if ($result === 'OK') {

            return implode("\n", [
                "Updated {$path}.",
                "",
                "Diff:",
                $diff,
            ]);
        }

        return "Error writing file: {$result}";
    }

    /**
     * [code patch]
     * --- a/path/to/file.php
     * +++ b/path/to/file.php
     * @@ -10,7 +10,7 @@
     * -    return $total;
     * +    return round($total, 2);
     * [/code]
     */
    public function patch(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $diff = trim($content);
        if (!$diff) {
            return 'Error: patch content is empty.';
        }

        $encoded = base64_encode($diff);

        // dry-run
        $dryRunCmd = sprintf(
            'tmp=$(mktemp /tmp/patch_XXXX.diff) && ' .
            'echo %s | base64 -d > "$tmp" && ' .
            'patch --dry-run -p1 < "$tmp" 2>&1; ' .
            'rm -f "$tmp"',
            escapeshellarg($encoded)
        );

        $dry = $this->execRaw($context, $dryRunCmd);

        if (stripos($dry, 'FAILED') !== false || stripos($dry, 'error') !== false) {
            return "Patch failed (dry-run):\n{$dry}";
        }

        // apply
        $applyCmd = sprintf(
            'tmp=$(mktemp /tmp/patch_XXXX.diff) && ' .

            // store diff
            'echo %s | base64 -d > "$tmp" && ' .

            // get files
            'files=$(grep "^+++ " "$tmp" | sed "s/^+++ b\\///") && ' .

            // save before
            'for f in $files; do cp "$f" "$f.before" 2>/dev/null || true; done && ' .

            // apply patch
            'patch -p1 < "$tmp" 2>&1 && ' .

            // diff
            'echo "--- Diff ---" && ' .
            'for f in $files; do diff -u "$f.before" "$f" 2>/dev/null || true; done && ' .

            // cleanup
            'rm -f "$tmp" && for f in $files; do rm -f "$f.before"; done',
            escapeshellarg($encoded)
        );

        $result = $this->execRaw($context, $applyCmd);

        return "Patch applied.\n\n{$result}";
    }

    public function edit(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $trimmed = trim($content);

        // 1. diff
        if (preg_match('/^--- .+\n\+\+\+ .+\n@@/m', $trimmed)) {
            return $this->patch($trimmed, $context);
        }

        $params = $this->parseKeyValue($trimmed);

        if (isset($params['path']) && isset($params['replace'])) {
            return $this->replace($trimmed, $context);
        }

        // fallback heuristics
        $hasDiffMarkers = preg_match('/^[+-]/m', $trimmed) && preg_match('/^@@ /m', $trimmed);
        $hasKeyValue = preg_match_all('/^(\w+):/m', $trimmed) >= 2;

        if ($hasDiffMarkers && !$hasKeyValue) {
            return $this->patch($trimmed, $context);
        }

        if ($hasKeyValue && isset($params['path'])) {
            return $this->replace($trimmed, $context);
        }

        return "Error: Could not parse edit content.";
    }

    public function write(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $params = $this->parseKeyValue($content);

        $path = $params['path'] ?? null;
        $path = $this->normalizePath($path);
        $data = $params['content'] ?? $params['text'] ?? $params['code'] ?? $params['data'] ?? null;

        if (!$path || $data === null) {
            return 'Error: write requires path and content.';
        }

        $type = $this->pathType($context, $path);
        if ($type === 'DIR') {
            return "Error: {$path} is a directory, not a file.";
        }

        $this->execRaw($context, sprintf(
            'mkdir -p %s',
            escapeshellarg(dirname($path))
        ));

        $encoded = base64_encode($data);

        $cmd = sprintf(
            'echo %s | base64 -d > %s',
            escapeshellarg($encoded),
            escapeshellarg($path)
        );

        $result = trim($this->execRaw($context, $cmd));

        $fileCheck = $this->pathType($context, $path);
        if ($fileCheck === 'FILE') {
            return "Written: {$path}";
        }

        return "Error writing {$path}: {$result}";
    }

    /**
     * [code batch]
     * 1. path: app/Models/User.php | search: getStatus | replace: fetchStatus | limit: 1
     * 2. path: app/Services/UserService.php | search: getStatus | replace: fetchStatus
     * 3. path: resources/js/components/UserCard.vue | search: getStatus | replace: fetchStatus
     * [/code batch]
     *
     * Or in tool_calls mode:
     * {method: "batch", content: "1. path:...\n2. path:..."}
     */
    public function batch(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Code plugin is disabled.';
        }

        $operations = $this->parseBatchOperations($content);

        if (empty($operations)) {
            return 'Error: No valid operations found. Format:\n'
                 . '1. path: file.php | search: old | replace: new\n'
                 . '2. path: file2.php | search: old | replace: new';
        }

        $results = [];
        $hasErrors = false;
        $totalFiles = count($operations);

        foreach ($operations as $i => $op) {
            $label = "[{$op['index']}/{$totalFiles}] {$op['path']}";

            try {
                // Checking for the existence of the file
                $type = $this->pathType($context, $op['path']);

                if ($type === 'NO') {
                    if ($op['create'] ?? false) {
                        $writeResult = $this->write(
                            "path: {$op['path']}\ncontent: {$op['replace']}",
                            $context
                        );
                        $results[] = "{$label} — CREATED";
                    } else {
                        $results[] = "{$label} — SKIPPED: file not found (use create:true to create)";
                        $hasErrors = true;
                    }
                    continue;
                }

                if ($type === 'DIR') {
                    $results[] = "{$label} — SKIPPED: is a directory";
                    $hasErrors = true;
                    continue;
                }

                // Constructing parameters for replace
                $replaceContent = "path: {$op['path']}\nsearch: {$op['search']}\nreplace: {$op['replace']}";
                if (isset($op['limit'])) {
                    $replaceContent .= "\nlimit: {$op['limit']}";
                }

                // Calling the existing replace
                $replaceResult = $this->replace($replaceContent, $context);

                if (str_starts_with($replaceResult, 'Error:') || str_starts_with($replaceResult, 'Warning:')) {
                    $results[] = "{$label} — FAILED: {$replaceResult}";
                    $hasErrors = true;
                } else {
                    $results[] = "{$label} — OK";
                }

            } catch (\Throwable $e) {
                $results[] = "{$label} — ERROR: {$e->getMessage()}";
                $hasErrors = true;
            }
        }

        $summary = $hasErrors
            ? "Batch completed with errors ({$totalFiles} files processed):"
            : "Batch completed successfully ({$totalFiles} files):";

        return $summary . "\n" . implode("\n", $results);
    }

    /**
     * Parse batch content into array of operations.
     *
     * Format:
     *   1. path: file.php | search: old | replace: new | limit: 1
     *   2. path: file2.php | search: old | replace: new | create: true
     *
     * Lines starting with # are comments.
     *
     * @return array<int, array{index: int, path: string, search: string, replace: string, limit?: int, create?: bool}>
     */
    private function parseBatchOperations(string $content): array
    {
        $operations = [];
        $lines = explode("\n", trim($content));

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments.
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Remove numbering (1. / 2. / [1] / etc.)
            $line = preg_replace('/^(\d+[\.\)]\s*|\[\d+\]\s*)/', '', trim($line));

            $line = str_replace(' | ', "\n", $line);

            // Parsing key-value pairs
            $params = $this->parseKeyValue($line);

            if (!isset($params['path']) || !isset($params['replace'])) {
                continue; // Skip invalid lines
            }

            $op = [
                'index'   => count($operations) + 1,
                'path'    => $params['path'],
                'search'  => $params['search'] ?? null,
                'replace' => $params['replace'],
            ];

            if (isset($params['limit'])) {
                $op['limit'] = (int) $params['limit'];
            }

            if (isset($params['create'])) {
                $op['create'] = filter_var($params['create'], FILTER_VALIDATE_BOOLEAN);
            }

            $operations[] = $op;
        }

        return $operations;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Execute a shell command in the assigned sandbox and return formatted output.
     */
    private function exec(PluginExecutionContext $context, string $cmd, string $label): string
    {
        $raw = $this->execRaw($context, $cmd);
        return "[{$label}]\n{$raw}";
    }

    /**
     * Execute a shell command and return raw output string.
     */
    private function execRaw(PluginExecutionContext $context, string $cmd): string
    {
        $sandboxId = $this->resolvesSandboxId($context);

        if (!$sandboxId) {
            return 'Error: no sandbox assigned to this preset or sandbox is not running.';
        }

        $user    = $context->get('user', 'sandbox-user');
        $timeout = 15;

        try {
            $result = $this->sandboxManager->executeCommand($sandboxId, $cmd, $user, $timeout);
            $output = trim($result->output);
            $error  = trim($result->error);

            if ($result->exitCode !== 0) {
                return "[exit={$result->exitCode}] " . ($error ?: $output ?: 'Unknown error');
            }

            return $output ?: '(no output)';

        } catch (\Throwable $e) {
            $this->logger->error('CodePlugin::exec error', [
                'preset_id' => $context->preset->id,
                'cmd'       => substr($cmd, 0, 200),
                'error'     => $e->getMessage(),
            ]);
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Resolve sandbox ID from preset assignment.
     */
    private function resolvesSandboxId(PluginExecutionContext $context): ?string
    {
        $assignment = $this->presetSandboxService->getAssignedSandbox($context->preset->id);

        if (!$assignment || $assignment['sandbox']->status !== 'running') {
            return null;
        }

        return $assignment['sandbox_id'];
    }

    /**
     * Split "path | modifier" into [path, modifier|null].
     *
     * @return array{string, string|null}
     */
    private function splitModifier(string $content): array
    {
        if (str_contains($content, ' | ')) {
            [$path, $modifier] = explode(' | ', $content, 2);
            return [trim($path), trim($modifier)];
        }
        return [trim($content), null];
    }

    /**
     * Parse simple "key: value" multiline format.
     *
     * @return array<string, string>
     */
    private function parseKeyValue(string $content): array
    {
        $content = str_replace(' | ', "\n", $content);
        $result = [];
        $lines  = explode("\n", $content);
        $currentKey = null;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*([\w\-]+):\s*(.*)$/', $line, $m)) {
                if ($currentKey !== null) {
                    $result[$currentKey] = implode("\n", $buffer);
                }
                $currentKey = $m[1];
                $buffer     = [$m[2]];
            } elseif ($currentKey !== null) {
                $buffer[] = $line;
            }
        }

        if ($currentKey !== null) {
            $result[$currentKey] = implode("\n", $buffer);
        }

        // Trim values
        return array_map('trim', $result);
    }

    private function getUnifiedDiff(PluginExecutionContext $context, string $path, string $old, string $new): string
    {
        // Write old/new to temporary files
        $oldEnc = base64_encode($old);
        $newEnc = base64_encode($new);

        $cmd = sprintf(
            'oldf=$(mktemp /tmp/old_XXXXXX) && newf=$(mktemp /tmp/new_XXXXXX) && ' .
            'echo %s | base64 -d > "$oldf" && ' .
            'echo %s | base64 -d > "$newf" && ' .
            'diff -u --label %s --label %s "$oldf" "$newf" 2>/dev/null || true && ' .
            'rm -f "$oldf" "$newf"',
            escapeshellarg($oldEnc),
            escapeshellarg($newEnc),
            escapeshellarg("a/$path"),
            escapeshellarg("b/$path"),
        );

        $diff = trim($this->execRaw($context, $cmd));
        return $diff ?: '(no diff)';
    }

    private function pathType(PluginExecutionContext $context, string $path): string
    {
        $cmd = sprintf(
            '[ -f %s ] && echo FILE || ([ -d %s ] && echo DIR || echo NO)',
            escapeshellarg($path),
            escapeshellarg($path)
        );

        return trim($this->execRaw($context, $cmd));
    }

    /**
     * Strip "key: " prefix and expand ~ to /home/sandbox-user.
     * Safe to call on any model-provided path input.
     */
    private function normalizePath(string $input): string
    {
        $input = trim($input);

        // If ": " is present, trim everything preceding it (the model may have added a "key:" prefix).
        if (str_contains($input, ': ')) {
            $input = trim(substr($input, strpos($input, ': ') + 2));
        }

        if ($input === '' || $input === '.') {
            return $input;
        }

        // Revealing...
        if (str_starts_with($input, '~/')) {
            $input = '/home/sandbox-user/' . substr($input, 2);
        } elseif ($input === '~') {
            $input = '/home/sandbox-user';
        }

        // Collapse /../ and /./ for security (but do not break legitimate paths).
        $input = preg_replace('#/\./#', '/', $input);  // /./ → /
        do {
            $prev = $input;
            $input = preg_replace('#/[^/]+/\.\./#', '/', $input);
        } while ($input !== $prev);  // /dir/../ → /
        $input = preg_replace('#^/\.\./#', '/', $input);  // в начале

        return $input;
    }

}
