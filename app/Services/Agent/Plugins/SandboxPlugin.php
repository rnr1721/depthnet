<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PresetSandboxServiceInterface;
use App\Contracts\Sandbox\SandboxManagerInterface;
use App\Contracts\Sandbox\SandboxServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;

/**
 * SandboxPlugin — stateless.
 *
 * Universal plugin for executing code and commands in Docker sandboxes.
 * Supports multiple languages: shell, php, python, node.
 * Uses preset-assigned sandbox.
 */
class SandboxPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    protected array $languages = [
      'shell'  => 'shell commands (bash)',
      'php'    => 'PHP code',
      'python' => 'Python code',
      'node'   => 'Node.js / JavaScript code',
    ];

    public function __construct(
        protected SandboxServiceInterface $sandboxService,
        protected PresetSandboxServiceInterface $presetSandboxService,
        protected SandboxManagerInterface $sandboxManager
    ) {
    }

    public function getName(): string
    {
        return 'run';
    }

    public function getDescription(array $config = []): string
    {
        $currentLanguages = [];
        foreach ($this->languages as $language => $description) {
            if ($this->isLanguageEnabled($config, $language)) {
                $currentLanguages[] = $description;
            }
        }
        return 'Execute code and commands in assigned Docker sandbox. Requires sandbox to be assigned to preset. Supports ' . implode(', ', $currentLanguages) . '.';
    }

    /**
     * instructions are static — they list all four runtimes.
     * Disabled languages will fail with a clear error at execute() time.
     */
    public function getInstructions(array $config = []): array
    {
        $instructions = [];

        // Shell commands
        if ($this->isLanguageEnabled($config, 'shell')) {
            $instructions = array_merge($instructions, [
                'Execute shell commands: [run shell]ls -la[/run]',
                'System operations: [run shell]ps aux | grep nginx[/run]',
                'File operations: [run shell]mkdir test && echo "hello" > test/file.txt[/run]',
                'Network testing: [run shell]ping -c 3 google.com[/run]',
            ]);
        }

        // PHP code
        if ($this->isLanguageEnabled($config, 'php')) {
            $instructions = array_merge($instructions, [
                'Execute PHP code: [run php]echo "Hello World!";[/run]',
                'PHP calculations: [run php]$result = 15 * 8 + 45; echo "Result: $result";[/run]',
                'PHP arrays: [run php]$data = [1,2,3]; echo array_sum($data);[/run]',
                'PHP JSON: [run php]$json = \'{"name":"John"}\'; $data = json_decode($json, true); echo $data["name"];[/run]',
            ]);
        }

        // Python code
        if ($this->isLanguageEnabled($config, 'python')) {
            $instructions = array_merge($instructions, [
                'Execute Python code: [run python]print("Hello World!")[/run]',
                'Python calculations: [run python]result = 15 * 8 + 45; print(f"Result: {result}")[/run]',
                'Python lists: [run python]data = [1,2,3]; print(sum(data))[/run]',
                'Python JSON: [run python]import json; data = json.loads(\'{"name":"John"}\'); print(data["name"])[/run]',
            ]);
        }

        // Node.js code
        if ($this->isLanguageEnabled($config, 'node')) {
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
     * tool schema is static — exposes all four languages.
     * Per-preset enable_* flags are enforced at execution time.
     */
    public function getToolSchema(array $config = []): array
    {
        // Build enum from languages enabled in the current preset config
        $enabledLanguages = array_values(array_filter(
            $this->languages,
            fn ($lang) => $this->isLanguageEnabled($config, $lang)
        ));

        $langList = implode(', ', array_map(
            fn ($lang) => $lang . ' (' . ($this->languages[$lang] ?? $lang) . ')',
            $enabledLanguages
        ));

        return [
            'name'        => 'run',
            'description' => 'Execute code or shell commands in an isolated Docker sandbox. '
                . 'Requires a sandbox to be assigned to this preset. '
                . "Available languages: {$langList}.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Execution language / runtime',
                        'enum'        => array_values($enabledLanguages),
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'The code or command to execute.',
                            'shell: a shell command or script, e.g. "ls -la /tmp".',
                            'php: PHP code without opening tags, e.g. "echo date(\'Y-m-d\');".',
                            'python: Python code, e.g. "import os; print(os.getcwd())".',
                            'node: Node.js code, e.g. "console.log(process.version)".',
                        ]),
                    ],
                ],
                'required'   => ['method', 'content'],
            ],
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return "Error executing code in sandbox.";
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Sandbox plugin is disabled.";
        }

        if (empty($content)) {
            $hint = "Command for execution shell commands and code\n";
            return $hint . implode("\n", $this->getInstructions());
        }

        try {
            $parsed = $this->parseCommand($content);
            $language = $parsed['language'];
            $code = $parsed['code'];

            if (!$this->isLanguageEnabled($context->config, $language)) {
                return "Error: {$language} execution is disabled in plugin configuration.";
            }

            $sandboxId = $this->getAssignedSandbox($context);
            if (!$sandboxId) {
                return "Error: No sandbox assigned to current preset or sandbox is stopped. Please assign a sandbox before code execution.";
            }

            $result = $this->executeInSandbox($context, $sandboxId, $language, $code);

            return $this->formatOutput($language, $code, $result);

        } catch (\Throwable $e) {
            return "Error executing code in sandbox: " . $e->getMessage();
        }
    }

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

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (isset($config['execution_timeout'])) {
            $timeout = (int) $config['execution_timeout'];
            if ($timeout < 5 || $timeout > 300) {
                $errors['execution_timeout'] = 'Execution timeout must be between 5 and 300 seconds';
            }
        }

        $anyEnabled = false;
        $languages = array_keys($this->languages);
        foreach ($languages as $language) {
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

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'enable_shell' => false,
            'enable_php' => true,
            'enable_python' => true,
            'enable_node' => true,
            'execution_timeout' => 30,
            'user' => 'sandbox-user',
            'temp_dir' => '/tmp',
        ];
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    // Method-call entry points: [run shell]…[/run] etc.

    public function shell(string $content, PluginExecutionContext $context): string
    {
        return $this->execute("shell\n" . $content, $context);
    }

    public function php(string $content, PluginExecutionContext $context): string
    {
        return $this->execute("php\n" . $content, $context);
    }

    public function python(string $content, PluginExecutionContext $context): string
    {
        return $this->execute("python\n" . $content, $context);
    }

    public function node(string $content, PluginExecutionContext $context): string
    {
        return $this->execute("node\n" . $content, $context);
    }

    public function getSelfClosingTags(): array
    {
        return [];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function parseCommand(string $content): array
    {
        $lines = explode("\n", trim($content), 2);

        if (count($lines) >= 2) {
            $language = trim($lines[0]);
            $code = trim($lines[1]);
        } else {
            $language = 'shell';
            $code = trim($content);
        }

        if (!array_key_exists($language, $this->languages)) {
            throw new \InvalidArgumentException("Unsupported language: {$language}");
        }

        return ['language' => $language, 'code' => $code];
    }

    private function getAssignedSandbox(PluginExecutionContext $context): ?string
    {
        $assignedSandbox = $this->presetSandboxService->getAssignedSandbox($context->preset->getId());

        if (!$assignedSandbox || $assignedSandbox['sandbox']->status !== 'running') {
            return null;
        }

        return $assignedSandbox['sandbox_id'];
    }

    private function executeInSandbox(PluginExecutionContext $context, string $sandboxId, string $language, string $code)
    {
        $timeout = (int) $context->get('execution_timeout', 30);

        return match ($language) {
            'shell'  => $this->executeShellCommand($context, $sandboxId, $code, $timeout),
            'php'    => $this->executePhpCode($context, $sandboxId, $code, $timeout),
            'python' => $this->executePythonCode($context, $sandboxId, $code, $timeout),
            'node'   => $this->executeNodeCode($context, $sandboxId, $code, $timeout),
            default  => throw new \InvalidArgumentException("Unsupported language: {$language}")
        };
    }

    private function executeShellCommand(PluginExecutionContext $context, string $sandboxId, string $command, int $timeout)
    {
        $executionResult = $this->sandboxManager->executeCommand(
            $sandboxId,
            $command,
            $context->get('user'),
            $timeout
        );

        return [
            'output' => $executionResult->output,
            'error' => $executionResult->error,
            'exit_code' => $executionResult->exitCode,
            'execution_time' => $executionResult->executionTime,
            'timed_out' => $executionResult->timedOut,
        ];
    }

    private function executePhpCode(PluginExecutionContext $context, string $sandboxId, string $code, int $timeout)
    {
        $this->sandboxService->setUser($context->get('user'));
        return $this->sandboxService->executeCodeInSandbox(
            $sandboxId,
            $code,
            'php',
            [
                'timeout'  => $timeout,
                'work_dir' => $context->get('temp_dir'),
            ]
        );
    }

    private function executePythonCode(PluginExecutionContext $context, string $sandboxId, string $code, int $timeout)
    {
        $this->sandboxService->setUser($context->get('user'));
        return $this->sandboxService->executeCodeInSandbox(
            $sandboxId,
            $code,
            'python',
            [
                'timeout'  => $timeout,
                'work_dir' => $context->get('temp_dir'),
            ]
        );
    }

    private function executeNodeCode(PluginExecutionContext $context, string $sandboxId, string $code, int $timeout)
    {
        $this->sandboxService->setUser($context->get('user'));
        return $this->sandboxService->executeCodeInSandbox(
            $sandboxId,
            $code,
            'javascript',
            [
                'timeout'  => $timeout,
                'work_dir' => $context->get('temp_dir'),
            ]
        );
    }

    private function formatOutput(string $language, string $code, $result): string
    {
        $output = [];

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

    private function isLanguageEnabled(array $config, string $language): bool
    {
        return (bool) ($config["enable_{$language}"] ?? true);
    }
}
