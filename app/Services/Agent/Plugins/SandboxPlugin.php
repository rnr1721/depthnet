<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Contracts\Sandbox\SandboxServiceInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;

/**
 * SandboxPlugin class
 *
 * Universal plugin for executing code and commands in Docker sandboxes.
 * Supports multiple languages: shell, php, python, node.
 * Uses preset-assigned sandbox or creates temporary ones.
 */
class SandboxPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    protected array $languages = ['shell','php','python','node'];

    public function __construct(
        protected SandboxServiceInterface $sandboxService,
        protected PresetSandboxServiceInterface $presetSandboxService,
        protected SandboxManagerInterface $sandboxManager
    ) {
        $this->sandboxService = $sandboxService;
        $this->presetSandboxService = $presetSandboxService;
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'run';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Execute code and commands in assigned Docker sandbox. Requires sandbox to be assigned to preset. Supports shell, PHP, Python, and Node.js.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        $instructions = [];

        // Shell commands
        if ($this->isLanguageEnabled('shell')) {
            $instructions = array_merge($instructions, [
                'Execute shell commands: [run shell]ls -la[/run]',
                'System operations: [run shell]ps aux | grep nginx[/run]',
                'File operations: [run shell]mkdir test && echo "hello" > test/file.txt[/run]',
                'Network testing: [run shell]ping -c 3 google.com[/run]',
            ]);
        }

        // PHP code
        if ($this->isLanguageEnabled('php')) {
            $instructions = array_merge($instructions, [
                'Execute PHP code: [run php]echo "Hello World!";[/run]',
                'PHP calculations: [run php]$result = 15 * 8 + 45; echo "Result: $result";[/run]',
                'PHP arrays: [run php]$data = [1,2,3]; echo array_sum($data);[/run]',
                'PHP JSON: [run php]$json = \'{"name":"John"}\'; $data = json_decode($json, true); echo $data["name"];[/run]',
            ]);
        }

        // Python code
        if ($this->isLanguageEnabled('python')) {
            $instructions = array_merge($instructions, [
                'Execute Python code: [run python]print("Hello World!")[/run]',
                'Python calculations: [run python]result = 15 * 8 + 45; print(f"Result: {result}")[/run]',
                'Python lists: [run python]data = [1,2,3]; print(sum(data))[/run]',
                'Python JSON: [run python]import json; data = json.loads(\'{"name":"John"}\'); print(data["name"])[/run]',
            ]);
        }

        // Node.js code
        if ($this->isLanguageEnabled('node')) {
            $instructions = array_merge($instructions, [
                'Execute Node.js code: [run node]console.log("Hello World!");[/run]',
                'Node.js calculations: [run node]const result = 15 * 8 + 45; console.log(`Result: ${result}`);[/run]',
                'Node.js arrays: [run node]const data = [1,2,3]; console.log(data.reduce((a,b) => a+b, 0));[/run]',
                'Node.js JSON: [run node]const data = JSON.parse(\'{"name":"John"}\'); console.log(data.name);[/run]',
            ]);
        }

        if (empty($instructions)) {
            $instructions[] = 'All execution languages are disabled in plugin configuration.';
            $instructions[] = 'Note: This plugin requires a sandbox to be assigned to the current preset.';
        } else {
            $instructions[] = 'Note: Sandbox must be assigned to preset before code execution.';
        }

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error executing code in sandbox.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Sandbox plugin is disabled.";
        }

        if (empty($content)) {
            $hint = "Command for execution shell commands and code";
            return $hint . implode("\n", $this->getInstructions());
        }

        try {
            // Parse command format: [run language]code[/run]
            $parsedCommand = $this->parseCommand($content);
            $language = $parsedCommand['language'];
            $code = $parsedCommand['code'];

            if (!$this->isLanguageEnabled($language)) {
                return "Error: {$language} execution is disabled in plugin configuration.";
            }

            $sandboxId = $this->getAssignedSandbox();
            if (!$sandboxId) {
                return "Error: No sandbox assigned to current preset or sandbox is stopped. Please assign a sandbox before code execution.";
            }

            $result = $this->executeInSandbox($sandboxId, $language, $code);

            return $this->formatOutput($language, $code, $result);

        } catch (\Throwable $e) {
            return "Error executing code in sandbox: " . $e->getMessage();
        }
    }

    /**
     * Parse command to extract language and code
     *
     * @param string $content
     * @return array
     */
    private function parseCommand(string $content): array
    {
        // Extract language from content if it's already parsed externally
        // Expected format: "language_name\ncode_content"
        $lines = explode("\n", trim($content), 2);

        if (count($lines) >= 2) {
            $language = trim($lines[0]);
            $code = trim($lines[1]);
        } else {
            // Fallback - assume it's shell if no language specified
            $language = 'shell';
            $code = trim($content);
        }

        // Validate language
        if (!in_array($language, $this->languages)) {
            throw new \InvalidArgumentException("Unsupported language: {$language}");
        }

        return [
            'language' => $language,
            'code' => $code
        ];
    }

    /**
     * Get assigned sandbox ID for current preset
     *
     * @return string|null
     */
    private function getAssignedSandbox(): ?string
    {
        if (!isset($this->preset)) {
            return null;
        }

        $assignedSandbox = $this->presetSandboxService->getAssignedSandbox($this->preset->id);

        if (!$assignedSandbox || $assignedSandbox['sandbox']->status !== 'running') {
            return null;
        }

        return $assignedSandbox['sandbox_id'];
    }

    /**
     * Execute code in sandbox based on language
     *
     * @param string $sandboxId
     * @param string $language
     * @param string $code
     * @return mixed
     */
    private function executeInSandbox(string $sandboxId, string $language, string $code)
    {
        $timeout = $this->config['execution_timeout'] ?? 30;

        return match ($language) {
            'shell' => $this->executeShellCommand($sandboxId, $code, $timeout),
            'php' => $this->executePhpCode($sandboxId, $code, $timeout),
            'python' => $this->executePythonCode($sandboxId, $code, $timeout),
            'node' => $this->executeNodeCode($sandboxId, $code, $timeout),
            default => throw new \InvalidArgumentException("Unsupported language: {$language}")
        };
    }

    /**
     * Execute shell command in sandbox
     *
     * @param string $sandboxId
     * @param string $command
     * @param int $timeout
     * @return mixed
     */
    private function executeShellCommand(string $sandboxId, string $command, int $timeout)
    {
        $executionResult = $this->sandboxManager->executeCommand(
            $sandboxId,
            $command,
            $this->config['user'],
            $timeout
        );

        return [
            'output' => $executionResult->output,
            'error' => $executionResult->error,
            'exit_code' => $executionResult->exitCode,
            'execution_time' => $executionResult->executionTime,
            'timed_out' => $executionResult->timedOut
        ];
    }

    /**
     * Execute PHP code in sandbox
     *
     * @param string $sandboxId
     * @param string $code
     * @param int $timeout
     * @return mixed
     */
    private function executePhpCode(string $sandboxId, string $code, int $timeout)
    {
        // Ensure PHP code has proper opening tags
        //if (!str_starts_with(trim($code), '<?php') && !str_starts_with(trim($code), '<?')) {
            //$code = "<?php\n" . $code;
        //}

        $this->sandboxService->setUser($this->config['user']);
        return $this->sandboxService->executeCodeInSandbox(
            $sandboxId,
            $code,
            'php',
            [
                'timeout' => $timeout,
                'work_dir' => $this->config['temp_dir']
            ]
        );
    }

    /**
     * Execute Python code in sandbox
     *
     * @param string $sandboxId
     * @param string $code
     * @param int $timeout
     * @return mixed
     */
    private function executePythonCode(string $sandboxId, string $code, int $timeout)
    {
        $this->sandboxService->setUser($this->config['user']);
        return $this->sandboxService->executeCodeInSandbox(
            $sandboxId,
            $code,
            'python',
            [
                'timeout' => $timeout,
                'work_dir' => $this->config['temp_dir']
            ]
        );
    }

    /**
     * Execute Node.js code in sandbox
     *
     * @param string $sandboxId
     * @param string $code
     * @param int $timeout
     * @return mixed
     */
    private function executeNodeCode(string $sandboxId, string $code, int $timeout)
    {
        $this->sandboxService->setUser($this->config['user']);
        return $this->sandboxService->executeCodeInSandbox(
            $sandboxId,
            $code,
            'javascript',
            [
                'timeout' => $timeout,
                'work_dir' => $this->config['temp_dir']
            ]
        );
    }

    /**
     * Format execution output
     *
     * @param string $language
     * @param string $code
     * @param mixed $result
     * @return string
     */
    private function formatOutput(string $language, string $code, $result): string
    {
        $output = [];

        // Add execution info
        if ($language === 'shell') {
            $output[] = "Command executed in sandbox:";
            $output[] = "> {$code}";
            $output[] = "";

            if (!empty($result['output'])) {
                $output[] = $result['output'];
            }

            if (!empty($result['error'])) {
                $output[] = "Error: " . $result['error'];
            }

            if ($result['exit_code'] !== 0) {
                $output[] = "Exit code: " . $result['exit_code'];
            }
        } else {
            $output[] = ucfirst($language) . " code executed in sandbox:";
            $output[] = "";

            if (!empty($result->output)) {
                $output[] = $result->output;
            }

            if (!empty($result->error)) {
                $output[] = "Error: " . $result->error;
            }

            if ($result->exitCode !== 0) {
                $output[] = "Exit code: " . $result->exitCode;
            }
        }

        return implode("\n", $output) ?: 'Code executed successfully with no output.';
    }

    /**
     * Check if language is enabled
     *
     * @param string $language
     * @return bool
     */
    private function isLanguageEnabled(string $language): bool
    {
        return $this->config["enable_{$language}"] ?? true;
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Sandbox Plugin',
                'description' => 'Allow code execution in Docker sandboxes',
                'required' => false
            ],
            'enable_shell' => [
                'type' => 'checkbox',
                'label' => 'Enable Shell Commands',
                'description' => 'Allow execution of shell commands in assigned sandbox',
                'value' => true,
                'required' => false
            ],
            'enable_php' => [
                'type' => 'checkbox',
                'label' => 'Enable PHP Code',
                'description' => 'Allow execution of PHP code in assigned sandbox',
                'value' => true,
                'required' => false
            ],
            'enable_python' => [
                'type' => 'checkbox',
                'label' => 'Enable Python Code',
                'description' => 'Allow execution of Python code in assigned sandbox',
                'value' => true,
                'required' => false
            ],
            'enable_node' => [
                'type' => 'checkbox',
                'label' => 'Enable Node.js Code',
                'description' => 'Allow execution of Node.js/JavaScript code in assigned sandbox',
                'value' => true,
                'required' => false
            ],
            'execution_timeout' => [
                'type' => 'number',
                'label' => 'Execution Timeout (seconds)',
                'description' => 'Maximum execution time for code/commands in assigned sandbox',
                'min' => 5,
                'max' => 300,
                'value' => 30,
                'required' => false
            ],
            'user' => [
                'type' => 'text',
                'label' => 'User for execute code',
                'description' => 'Set user for code execution',
                'placeholder' => 'sandbox-user',
                'required' => true
            ],
            'temp_dir' => [
                'type' => 'text',
                'label' => 'Temporary directory',
                'description' => 'Set temp dir for code execution',
                'placeholder' => '/tmp',
                'required' => true
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Validate timeout
        if (isset($config['execution_timeout'])) {
            $timeout = (int) $config['execution_timeout'];
            if ($timeout < 5 || $timeout > 300) {
                $errors['execution_timeout'] = 'Execution timeout must be between 5 and 300 seconds';
            }
        }

        // Check that at least one language is enabled
        $anyEnabled = false;

        foreach ($this->languages as $language) {
            if ($config["enable_{$language}"] ?? true) {
                $anyEnabled = true;
                break;
            }
        }

        if (!$anyEnabled) {
            $errors['languages'] = 'At least one execution language must be enabled';
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
            'enable_shell' => true,
            'enable_php' => true,
            'enable_python' => true,
            'enable_node' => true,
            'execution_timeout' => 30,
            'user' => 'sandbox-user',
            'temp_dir' => '/tmp'
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

        // Check if sandbox is assigned
        $sandboxId = $this->getAssignedSandbox();
        if (!$sandboxId) {
            return false;
        }

        try {
            // Test with simple shell command if enabled
            if ($this->isLanguageEnabled('shell')) {
                $result = $this->execute("shell\necho 'Run plugin test successful'");
                return str_contains($result, 'Run plugin test successful');
            }

            return true; // If shell is disabled but sandbox is assigned, consider it working
        } catch (\Exception $e) {
            return false;
        }
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
        return false; // Each sandbox execution should be separate for clarity
    }

    /**
     * @inheritDoc
     */
    public function pluginReady(): void
    {
        // Nothing to do here since we don't create temporary sandboxes
    }

    /**
     * Execute shell command method (for method calling)
     *
     * @param string $content
     * @return string
     */
    public function shell(string $content): string
    {
        return $this->execute("shell\n" . $content);
    }

    /**
     * Execute PHP code method (for method calling)
     *
     * @param string $content
     * @return string
     */
    public function php(string $content): string
    {
        return $this->execute("php\n" . $content);
    }

    /**
     * Execute Python code method (for method calling)
     *
     * @param string $content
     * @return string
     */
    public function python(string $content): string
    {
        return $this->execute("python\n" . $content);
    }

    /**
     * Execute Node.js code method (for method calling)
     *
     * @param string $content
     * @return string
     */
    public function node(string $content): string
    {
        return $this->execute("node\n" . $content);
    }

}
