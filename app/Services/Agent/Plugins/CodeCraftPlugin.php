<?php

declare(strict_types=1);

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;
use rnr1721\CodeCraft\Interfaces\CodeCraftInterface;
use Psr\Log\LoggerInterface;

/**
 * CodeCraft Plugin for DepthNet
 *
 * Provides code generation and file manipulation capabilities using CodeCraft library.
 * Supports multiple programming languages and file types with intelligent path-based type detection.
 */
class CodeCraftPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected CodeCraftInterface $codeCraft,
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'codecraft';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $supportedExtensions = $this->codeCraft->getSupportedExtensions();
        $extensionCount = count($supportedExtensions);

        return "Generate and manipulate code files using CodeCraft. {$extensionCount} file types supported: " . implode(', ', $supportedExtensions) . ". Uses simple path-based API with automatic type detection.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        $supportedExtensions = $this->codeCraft->getSupportedExtensions();

        $instructions = [
            'Get general help: [codecraft][/codecraft]',
            'Get plugin status: [codecraft status][/codecraft]',
        ];

        if (!empty($supportedExtensions)) {
            $instructions[] = "Get language help (available: " . implode(', ', $supportedExtensions) . "): [codecraft help]php[/codecraft]";
        }

        // Add examples for each supported extension
        foreach ($supportedExtensions as $ext) {
            try {
                $help = $this->codeCraft->getHelp($ext);

                if (!empty($help['examples'])) {
                    $firstExample = array_values($help['examples'])[0];
                    if (isset($firstExample['description'])) {
                        $description = $firstExample['description'];
                        $instructions[] = "{$description}: [codecraft]{{\"path\":\"Example.{$ext}\",\"name\":\"Example\",...}}[/codecraft]";
                    }
                } else {
                    // Fallback examples based on extension
                    $instructions[] = $this->getExampleForExtension($ext);
                }
            } catch (\Exception $e) {
                $instructions[] = "Generate {$ext} code: [codecraft]{{\"path\":\"Example.{$ext}\",\"name\":\"Example\"}}[/codecraft]";
            }
        }

        $instructions = array_merge($instructions, [
            'Generate and save file: [codecraft save]{"path":"/tmp/codecraft/Example.php","name":"Example","namespace":"App"}[/codecraft]',
            'Edit existing file: [codecraft edit]{"path":"/path/to/file","modifications":[{"type":"add_method","method":{"name":"newMethod"}}]}[/codecraft]',
            'List files in directory: [codecraft list]/tmp/codecraft[/codecraft]',
            'Analyze existing file: [codecraft analyze]/path/to/file[/codecraft]',
            'Validate file: [codecraft validate]/path/to/file[/codecraft]',
        ]);

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "CodeCraft operation completed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "CodeCraft operation failed.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        $content = trim($content);

        // Empty content = show general help
        if (empty($content)) {
            return $this->showGeneralHelp();
        }

        try {
            // Check if it's JSON content for code generation
            if ($this->isJson($content)) {
                return $this->generateCode($content);
            }

            return $this->handleTextRequest($content);

        } catch (\Throwable $e) {
            $this->logger->error("CodeCraft plugin error: " . $e->getMessage(), [
                'content' => $content,
                'trace' => $e->getTraceAsString()
            ]);
            return "Error: " . $e->getMessage();
        }
    }

    /**
     * Show help for specific language/extension
     *
     * @param string $content Extension name
     * @return string
     */
    public function help(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        $extension = trim($content);

        if (empty($extension)) {
            return $this->showGeneralHelp();
        }

        try {
            if (!$this->codeCraft->supports($extension)) {
                $supportedExtensions = $this->codeCraft->getSupportedExtensions();
                return "Extension '{$extension}' is not supported.\n\n" .
                       "**Supported extensions:** " . implode(', ', $supportedExtensions);
            }

            $help = $this->codeCraft->getHelp($extension);

            $output = "**{$extension} Adapter Help**\n\n";

            if (isset($help['description'])) {
                $output .= "**Description:** {$help['description']}\n\n";
            }

            if (!empty($help['examples'])) {
                $output .= "**Examples:**\n";
                foreach ($help['examples'] as $name => $example) {
                    $output .= "• **{$name}:** {$example['description']}\n";
                    if (isset($example['options'])) {
                        $options = json_encode($example['options'], JSON_PRETTY_PRINT);
                        $output .= "  ```json\n{$options}\n  ```\n";
                    }
                }
                $output .= "\n";
            }

            if (!empty($help['options'])) {
                $output .= "**Available Options:**\n";
                foreach ($help['options'] as $option => $description) {
                    $output .= "• **{$option}:** {$description}\n";
                }
                $output .= "\n";
            }

            $output .= "**CodeCraft Plugin Usage:**\n";
            $output .= "• Generate: `[codecraft]{\"path\":\"Example.{$extension}\",\"name\":\"Example\",...}[/codecraft]`\n";
            $output .= "• Save file: `[codecraft save]{\"path\":\"/tmp/codecraft/Example.{$extension}\",\"name\":\"Example\"}[/codecraft]`\n";

            return $output;

        } catch (\Exception $e) {
            return "Error getting help for '{$extension}': " . $e->getMessage();
        }
    }

    /**
     * Analyze existing file
     *
     * @param string $content File path
     * @return string
     */
    public function analyze(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        $filePath = trim($content);

        if (empty($filePath)) {
            return "Error: File path is required for analysis.";
        }

        try {
            if (!file_exists($filePath)) {
                return "Error: File '{$filePath}' does not exist.";
            }

            $analysis = $this->codeCraft->analyze($filePath);
            return $this->formatAnalysis($analysis, $filePath);

        } catch (\Exception $e) {
            return "Error analyzing file '{$filePath}': " . $e->getMessage();
        }
    }

    /**
     * Validate file
     *
     * @param string $content File path
     * @return string
     */
    public function validate(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        $filePath = trim($content);

        if (empty($filePath)) {
            return "Error: File path is required for validation.";
        }

        try {
            if (!file_exists($filePath)) {
                return "Error: File '{$filePath}' does not exist.";
            }

            $isValid = $this->codeCraft->validate($filePath);

            return $isValid
                ? "File '{$filePath}' is valid."
                : "File '{$filePath}' has validation errors.";

        } catch (\Exception $e) {
            return "Error during validation: " . $e->getMessage();
        }
    }

    /**
     * Show plugin status
     *
     * @param string $content
     * @return string
     */
    public function status(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        $supportedExtensions = $this->codeCraft->getSupportedExtensions();
        $config = $this->getConfig();

        $status = "**CodeCraft Plugin Status**\n\n";
        $status .= "**Status:** " . ($this->isEnabled() ? "Enabled" : "Disabled") . "\n";
        $status .= "**Extensions:** " . count($supportedExtensions) . " supported\n";
        $status .= "**File Types:** " . implode(', ', $supportedExtensions) . "\n";
        $status .= "**Auto-save:** " . ($config['auto_save'] ? 'Enabled' : 'Disabled') . "\n";

        if (!empty($config['default_output_path'])) {
            $status .= "**Output Path:** {$config['default_output_path']}\n";
        }

        $status .= "**Max File Size:** " . ($config['max_file_size'] ?? 100) . "KB\n";
        $status .= "**Path Protection:** " . ($config['protect_current_project'] ? 'Enabled' : 'Disabled') . "\n";

        $status .= "\n**Supported Extensions:**\n";
        foreach ($supportedExtensions as $ext) {
            $status .= "• .{$ext} - Use `[codecraft help]{$ext}[/codecraft]` for details\n";
        }

        return $status;
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable CodeCraft Plugin',
                'description' => 'Allow code generation and file manipulation',
                'required' => false
            ],
            'protect_current_project' => [
                'type' => 'checkbox',
                'label' => 'Protect Current Project',
                'description' => 'Prevent writing files to the DepthNet project directory',
                'value' => true,
                'required' => false
            ],
            'auto_save' => [
                'type' => 'checkbox',
                'label' => 'Auto Save Generated Files',
                'description' => 'Automatically save generated code to files',
                'value' => false,
                'required' => false
            ],
            'default_output_path' => [
                'type' => 'text',
                'label' => 'Default Output Path',
                'description' => 'Default directory for generated files (outside current project)',
                'placeholder' => '/tmp/codecraft',
                'required' => false
            ],
            'max_file_size' => [
                'type' => 'number',
                'label' => 'Max File Size (KB)',
                'description' => 'Maximum size for generated files',
                'min' => 1,
                'max' => 1024,
                'value' => 100,
                'required' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Validate max_file_size
        if (isset($config['max_file_size'])) {
            $size = (int) $config['max_file_size'];
            if ($size < 1 || $size > 1024) {
                $errors['max_file_size'] = 'Max file size must be between 1 and 1024 KB';
            }
        }

        // Validate output path
        if (!empty($config['default_output_path'])) {
            $path = $config['default_output_path'];
            if (strpos($path, '..') !== false) {
                $errors['default_output_path'] = 'Output path cannot contain ".." for security reasons';
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'protect_current_project' => true,
            'auto_save' => false,
            'default_output_path' => '/tmp/codecraft',
            'max_file_size' => 100,
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            // Test basic CodeCraft functionality
            $supportedExtensions = $this->codeCraft->getSupportedExtensions();
            return !empty($supportedExtensions);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate code from JSON specification
     *
     * @param string $jsonContent
     * @return string
     */
    private function generateCode(string $jsonContent): string
    {
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return "Error: Invalid JSON format. " . json_last_error_msg();
        }

        if (!isset($data['path'])) {
            return "Error: 'path' field is required in JSON specification for path-based API.";
        }

        $path = $data['path'];

        try {
            // Use new path-based API
            $content = $this->codeCraft->create($path, $data);

            // Get file extension for syntax highlighting
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            return "**Code generated successfully for {$path}:**\n\n```{$extension}\n{$content}\n```";

        } catch (\Exception $e) {
            return "Error generating code for {$path}: " . $e->getMessage();
        }
    }

    /**
     * Handle text-based requests
     *
     * @param string $content
     * @return string
     */
    private function handleTextRequest(string $content): string
    {
        // Simple text commands
        $lowered = strtolower(trim($content));

        switch ($lowered) {
            case 'help':
            case '?':
                return $this->showGeneralHelp();

            case 'status':
                return $this->status('');

            default:
                return "Unknown command: '{$content}'\n\nUse empty [codecraft][/codecraft] for help.";
        }
    }

    /**
     * Show general help information
     *
     * @return string
     */
    private function showGeneralHelp(): string
    {
        $supportedExtensions = $this->codeCraft->getSupportedExtensions();

        $output = "**CodeCraft Plugin Help**\n\n";

        $output .= "**Plugin Status:**\n";
        $output .= "• File types supported: " . count($supportedExtensions) . "\n";
        $output .= "• Supported extensions: " . implode(', ', $supportedExtensions) . "\n\n";

        $output .= "**Available Commands:**\n";
        $output .= "• `[codecraft][/codecraft]` - Show this help\n";
        $output .= "• `[codecraft help]<extension>[/codecraft]` - Get help for specific extension\n";
        $output .= "• `[codecraft status][/codecraft]` - Show plugin status and capabilities\n";
        $output .= "• `[codecraft analyze]<file-path>[/codecraft]` - Analyze existing file\n";
        $output .= "• `[codecraft validate]<file-path>[/codecraft]` - Validate file syntax\n\n";

        $output .= "**Supported File Types:**\n";
        foreach ($supportedExtensions as $ext) {
            $output .= "• **.{$ext}** - `[codecraft help]{$ext}[/codecraft]`\n";
        }
        $output .= "\n";

        $output .= "**Code Generation (Path-Based API):**\n";
        $output .= "Use JSON format with file path for automatic type detection:\n";
        $output .= "```json\n";
        $output .= "{\n";
        $output .= "  \"path\": \"app/Models/User.php\",\n";
        $output .= "  \"name\": \"User\",\n";
        $output .= "  \"namespace\": \"App\\\\Models\",\n";
        $output .= "  \"methods\": [{\"name\": \"getName\", \"returnType\": \"string\"}]\n";
        $output .= "}\n";
        $output .= "```\n\n";

        $output .= "**File Operations:**\n";
        $output .= "• **Save:** `[codecraft save]{\"path\":\"/tmp/Example.php\",\"name\":\"Example\"}[/codecraft]`\n";
        $output .= "• **Edit:** `[codecraft edit]{\"path\":\"/tmp/file.php\",\"modifications\":[...]}[/codecraft]`\n";
        $output .= "• **List:** `[codecraft list]/tmp/codecraft[/codecraft]`\n\n";

        $output .= "**Tip:** File type is automatically detected from the path extension!";

        return $output;
    }

    /**
     * Format analysis results
     *
     * @param array $analysis
     * @param string $filePath
     * @return string
     */
    private function formatAnalysis(array $analysis, string $filePath): string
    {
        $output = "**File Analysis: {$filePath}**\n\n";
        $output .= "**Type:** {$analysis['type']}\n";

        if (isset($analysis['namespace'])) {
            $output .= "**Namespace:** {$analysis['namespace']}\n";
        }

        if (!empty($analysis['classes'])) {
            $output .= "\n**Classes:**\n";
            foreach ($analysis['classes'] as $class) {
                $output .= "• {$class['name']}\n";
                if (!empty($class['methods'])) {
                    $output .= "  Methods: " . implode(', ', array_column($class['methods'], 'name')) . "\n";
                }
            }
        }

        if (!empty($analysis['interfaces'])) {
            $output .= "\n**Interfaces:**\n";
            foreach ($analysis['interfaces'] as $interface) {
                $output .= "• {$interface['name']}\n";
            }
        }

        if (!empty($analysis['functions'])) {
            $output .= "\n**Functions:** " . implode(', ', array_column($analysis['functions'], 'name')) . "\n";
        }

        return $output;
    }

    /**
     * Get example instruction for extension
     *
     * @param string $ext
     * @return string
     */
    private function getExampleForExtension(string $ext): string
    {
        $examples = [
            'php' => 'Generate PHP class: [codecraft]{"path":"User.php","name":"User","namespace":"App"}[/codecraft]',
            'js' => 'Generate JavaScript function: [codecraft]{"path":"utils.js","type":"function","name":"formatDate"}[/codecraft]',
            'ts' => 'Generate TypeScript interface: [codecraft]{"path":"User.ts","type":"interface","name":"User"}[/codecraft]',
            'json' => 'Generate JSON config: [codecraft]{"path":"config.json","type":"config","app_name":"MyApp"}[/codecraft]',
            'css' => 'Generate CSS styles: [codecraft]{"path":"styles.css","type":"component","name":"button"}[/codecraft]',
            'py' => 'Generate Python class: [codecraft]{"path":"user.py","type":"class","name":"User"}[/codecraft]',
        ];

        return $examples[$ext] ?? "Generate {$ext} code: [codecraft]{\"path\":\"Example.{$ext}\",\"name\":\"Example\"}[/codecraft]";
    }

    /**
     * Check if content is valid JSON
     *
     * @param string $content
     * @return bool
     */
    private function isJson(string $content): bool
    {
        json_decode($content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Save generated code to specific file
     *
     * @param string $content JSON with path and options
     * @return string
     */
    public function save(string $content): string
    {
        try {
            $data = json_decode($content, true);

            if (!isset($data['path'])) {
                return "Error: 'path' is required for save operation.";
            }

            $path = $data['path'];

            if (!$this->isPathAllowed($path)) {
                return "Error: File path is not allowed: {$path}";
            }

            $success = $this->codeCraft->write($path, $data);

            return $success
                ? "File saved successfully: {$path}"
                : "Failed to save file: {$path}";

        } catch (\Exception $e) {
            return "Error saving file: " . $e->getMessage();
        }
    }

    /**
     * Edit existing file
     *
     * @param string $content JSON with file path and modifications
     * @return string
     */
    public function edit(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        try {
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return "Error: Invalid JSON format for edit operation.";
            }

            if (!isset($data['path']) || !isset($data['modifications'])) {
                return "Error: Both 'path' and 'modifications' are required for edit operation.";
            }

            $path = $data['path'];
            $modifications = $data['modifications'];

            if (!$this->isPathAllowed($path)) {
                return "Error: File path is not allowed: {$path}";
            }

            if (!file_exists($path)) {
                return "Error: File does not exist: {$path}";
            }

            $result = $this->codeCraft->edit($path, $modifications);
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            return "File edited successfully:\n\n```{$extension}\n{$result}\n```";

        } catch (\Exception $e) {
            return "Error editing file: " . $e->getMessage();
        }
    }

    /**
     * List files in allowed directories
     *
     * @param string $content Directory path (optional)
     * @return string
     */
    public function list(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: CodeCraft plugin is disabled.";
        }

        $directory = trim($content) ?: ($this->config['default_output_path'] ?? '/tmp/codecraft');

        if (!$this->isPathAllowed($directory . '/dummy.txt')) {
            return "Error: Directory is not allowed: {$directory}";
        }

        if (!is_dir($directory)) {
            return "Error: Directory does not exist: {$directory}";
        }

        try {
            $files = [];
            $iterator = new \DirectoryIterator($directory);

            foreach ($iterator as $file) {
                if ($file->isDot()) {
                    continue;
                }

                $fileName = $file->getFilename();
                $fileType = $file->isDir() ? 'DIR' : strtoupper($file->getExtension());
                $fileSize = $file->isDir() ? '-' : $this->formatFileSize($file->getSize());

                $files[] = [
                    'name' => $fileName,
                    'type' => $fileType,
                    'size' => $fileSize,
                    'modified' => date('Y-m-d H:i', $file->getMTime())
                ];
            }

            if (empty($files)) {
                return "Directory is empty: {$directory}";
            }

            usort($files, function ($a, $b) {
                if ($a['type'] === 'DIR' && $b['type'] !== 'DIR') {
                    return -1;
                }
                if ($a['type'] !== 'DIR' && $b['type'] === 'DIR') {
                    return 1;
                }
                return strcmp($a['name'], $b['name']);
            });

            $output = "**Files in {$directory}:**\n\n";
            foreach ($files as $file) {
                $icon = $file['type'] === 'DIR' ? 'Dir' : 'File';
                $output .= "{$icon} {$file['name']} ({$file['type']}) - {$file['size']} - {$file['modified']}\n";
            }

            return $output;

        } catch (\Exception $e) {
            return "Error listing directory: " . $e->getMessage();
        }
    }

    /**
     * Check if path is within allowed directories
     *
     * @param string $path
     * @return bool
     */
    private function isPathAllowed(string $path): bool
    {
        $realPath = realpath(dirname($path)) ?: dirname($path);
        $depthNetPath = realpath(base_path());

        // Protect current project if enabled
        if (($this->config['protect_current_project'] ?? true) && $depthNetPath && strpos($realPath, $depthNetPath) === 0) {
            return false;
        }

        return $this->canCreatePath($path);
    }

    /**
     * Check if path can be created
     *
     * @param string $path
     * @return bool
     */
    private function canCreatePath(string $path): bool
    {
        $dir = dirname($path);

        if (is_dir($dir)) {
            return is_writable($dir);
        }

        try {
            return mkdir($dir, 0755, true);
        } catch (\Exception $e) {
            $this->logger->debug("Cannot create directory: {$dir}");
            return false;
        }
    }

    /**
     * Format file size for display
     *
     * @param int $bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function pluginReady(): void
    {
        // Nothing to do here
    }

}
