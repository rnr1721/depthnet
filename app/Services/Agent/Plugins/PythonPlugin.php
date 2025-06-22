<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;

/**
 * PythonPlugin class
 *
 * Executes Python code in a secure and isolated environment.
 * Supports virtual environments, package management, and scientific computing.
 * Provides configuration options for execution mode, user, timeout, virtual environments, etc.
 */
class PythonPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;

    public function __construct()
    {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'python';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Execute Python code in isolated environment. Use for data analysis, machine learning, scientific computing, automation scripts.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        $instructions = [
            'Execute simple Python: [python]print("Hello World!")[/python]',
            'Mathematical calculations: [python]result = 15 * 8 + 45; print(f"Result: {result}")[/python]',
            'Lists and comprehensions: [python]data = [1,2,3,4,5]; squares = [x**2 for x in data]; print(sum(squares))[/python]',
            'File operations: [python]with open("test.txt", "w") as f: f.write("Hello"); print("File created")[/python]',
            'JSON processing: [python]import json; data = json.loads(\'{"name":"John"}\'); print(data["name"])[/python]',
            'Dictionary operations: [python]data = {"a": 1, "b": 2}; print({k: v*2 for k, v in data.items()})[/python]',
        ];

        // Add data science examples if packages are allowed
        if ($this->config['allow_packages'] ?? false || $this->config['unrestricted_mode'] ?? false) {
            $instructions[] = 'Data analysis: [python]import pandas as pd; df = pd.DataFrame({"x": [1,2,3], "y": [4,5,6]}); print(df.sum())[/python]';
            $instructions[] = 'HTTP requests: [python]import requests; r = requests.get("https://api.github.com/users/github"); print(r.status_code)[/python]';
        }

        // Add advanced examples for unrestricted mode
        if ($this->config['unrestricted_mode'] ?? false) {
            $instructions[] = 'System commands: [python]import subprocess; result = subprocess.run(["ls", "-la"], capture_output=True, text=True); print(result.stdout)[/python]';
            $instructions[] = 'Package installation: [python]import subprocess; subprocess.run(["pip", "install", "requests"]); print("Package installed")[/python]';
            $instructions[] = 'Virtual environment: [python]import subprocess; subprocess.run(["python", "-m", "venv", "myenv"]); print("Venv created")[/python]';
        }

        return $instructions;
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Python code executed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error executing Python code.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Python plugin is disabled.";
        }

        if (!$this->isPythonAvailable()) {
            return "Error: Python is not installed or not available in PATH.";
        }

        try {
            $content = $this->normalizePythonCode($content);

            return $this->executeWithExternalProcess($content);

        } catch (\Throwable $e) {
            return "Error while executing Python code: " . $e->getMessage();
        }
    }

    /**
     * Execute Python code in external process
     *
     * @param string $content Python code to execute
     * @return string Execution output
     */
    private function executeWithExternalProcess(string $content): string
    {
        $tempFile = sys_get_temp_dir() . '/agent_python_' . uniqid() . '.py';
        file_put_contents($tempFile, $content);
        try {
            $command = $this->buildCommand($tempFile);
            $output = shell_exec("$command 2>&1");
            return $output ?: 'Code executed successfully with no output.';
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Python Plugin',
                'description' => 'Allow execution of Python code',
                'required' => false
            ],
            'python_path' => [
                'type' => 'text',
                'label' => 'Python Path',
                'description' => 'Path to Python executable (leave empty for auto-detection)',
                'placeholder' => '/usr/bin/python3',
                'required' => false
            ],
            'use_virtual_env' => [
                'type' => 'checkbox',
                'label' => 'Use Virtual Environment',
                'description' => 'Execute code in a virtual environment',
                'value' => false,
                'required' => false
            ],
            'virtual_env_path' => [
                'type' => 'text',
                'label' => 'Virtual Environment Path',
                'description' => 'Path to virtual environment (only if Use Virtual Environment is enabled)',
                'placeholder' => '/path/to/venv',
                'required' => false
            ],
            'user' => [
                'type' => 'text',
                'label' => 'Execution User',
                'description' => 'User to execute Python code as (leave empty for current user)',
                'placeholder' => 'devilbox',
                'required' => false
            ],
            'timeout' => [
                'type' => 'number',
                'label' => 'Timeout (seconds)',
                'description' => 'Maximum execution time for Python scripts',
                'min' => 1,
                'max' => 300,
                'value' => 30,
                'required' => false
            ],
            'working_directory' => [
                'type' => 'text',
                'label' => 'Working Directory',
                'description' => 'Directory where Python scripts will be executed',
                'placeholder' => '/shared/httpd',
                'required' => false
            ],
            'python_path_env' => [
                'type' => 'text',
                'label' => 'PYTHONPATH Environment',
                'description' => 'Additional Python paths (colon-separated)',
                'placeholder' => '/custom/modules:/another/path',
                'required' => false
            ],
            'allow_packages' => [
                'type' => 'checkbox',
                'label' => 'Allow External Packages',
                'description' => 'Allow importing packages like pandas, requests, numpy',
                'value' => false,
                'required' => false
            ],
            'safe_mode' => [
                'type' => 'checkbox',
                'label' => 'Safe Mode',
                'description' => 'Enable additional security restrictions (limit dangerous modules)',
                'value' => true,
                'required' => false
            ],
            'unrestricted_mode' => [
                'type' => 'checkbox',
                'label' => 'Unrestricted Mode',
                'description' => 'Disable all security restrictions. WARNING: This allows full system access!',
                'value' => false,
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

        if (isset($config['timeout'])) {
            $timeout = (int) $config['timeout'];
            if ($timeout < 1 || $timeout > 300) {
                $errors['timeout'] = 'Timeout must be between 1 and 300 seconds';
            }
        }

        if (!empty($config['python_path'])) {
            $pythonPath = $config['python_path'];
            if (!is_executable($pythonPath)) {
                $errors['python_path'] = "Python executable not found or not executable at '{$pythonPath}'";
            }
        }

        if (!empty($config['use_virtual_env']) && !empty($config['virtual_env_path'])) {
            $venvPath = $config['virtual_env_path'];
            $pythonInVenv = $venvPath . '/bin/python';

            if (!is_dir($venvPath)) {
                $errors['virtual_env_path'] = "Virtual environment directory '{$venvPath}' does not exist";
            } elseif (!is_executable($pythonInVenv)) {
                $errors['virtual_env_path'] = "Python executable not found in virtual environment at '{$pythonInVenv}'";
            }
        }

        if (!empty($config['working_directory'])) {
            $dir = $config['working_directory'];
            if (!is_dir($dir)) {
                $errors['working_directory'] = "Directory '{$dir}' does not exist";
            }
        }

        if (!empty($config['user'])) {
            $user = $config['user'];
            if (!$this->userExists($user)) {
                $errors['user'] = "User '{$user}' does not exist on this system";
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
            'python_path' => $this->detectPythonPath(),
            'use_virtual_env' => false,
            'virtual_env_path' => '',
            'user' => config('ai.plugins.execution_user', ''),
            'timeout' => 30,
            'working_directory' => config('ai.plugins.python.working_directory', sys_get_temp_dir()),
            'python_path_env' => '',
            'allow_packages' => false,
            'safe_mode' => true,
            'unrestricted_mode' => false
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

        if (!$this->isPythonAvailable()) {
            return false;
        }

        try {
            $testCode = "print('Python plugin test successful')";
            $result = $this->execute($testCode);
            return str_contains($result, 'Python plugin test successful');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build command with user and settings for external Python execution
     *
     * @param string $tempFile Path to temporary Python file
     * @return string Complete command to execute
     */
    private function buildCommand(string $tempFile): string
    {
        $user = $this->config['user'] ?? '';
        $timeout = $this->config['timeout'] ?? 30;
        $workingDir = $this->config['working_directory'] ?? sys_get_temp_dir();
        $pythonPath = $this->getPythonExecutable();

        $envVars = [];

        if (!empty($this->config['python_path_env'])) {
            $envVars[] = "PYTHONPATH=" . escapeshellarg($this->config['python_path_env']);
        }

        if (($this->config['safe_mode'] ?? true) && !($this->config['unrestricted_mode'] ?? false)) {
            $envVars[] = "PYTHONDONTWRITEBYTECODE=1"; // Don't create .pyc files
        }

        $envString = empty($envVars) ? '' : implode(' ', $envVars) . ' ';

        $command = "cd " . escapeshellarg($workingDir) . " && {$envString}timeout $timeout " .
                  escapeshellarg($pythonPath) . " " . escapeshellarg($tempFile);

        // Add user switching if specified and different from current
        if (!empty($user) && $user !== trim(shell_exec('whoami'))) {
            if (!$this->canSwitchUser()) {
                throw new \Exception("Cannot switch to user '$user': insufficient privileges");
            }

            if (!empty(shell_exec('command -v runuser 2>/dev/null'))) {
                $command = "runuser -u " . escapeshellarg($user) . " -- bash -c " . escapeshellarg($command);
            } elseif (!empty(shell_exec('command -v su 2>/dev/null'))) {
                $command = "su " . escapeshellarg($user) . " -c " . escapeshellarg($command);
            } else {
                $command = "sudo -u " . escapeshellarg($user) . " bash -c " . escapeshellarg($command);
            }
        }

        return $command;
    }

    /**
     * Get Python executable path (considering virtual environment)
     *
     * @return string
     */
    private function getPythonExecutable(): string
    {
        if (($this->config['use_virtual_env'] ?? false) && !empty($this->config['virtual_env_path'])) {
            $venvPython = $this->config['virtual_env_path'] . '/bin/python';
            if (is_executable($venvPython)) {
                return $venvPython;
            }
        }

        return $this->config['python_path'] ?? $this->detectPythonPath();
    }

    /**
     * Check if Python is available
     *
     * @return bool
     */
    private function isPythonAvailable(): bool
    {
        $pythonPath = $this->getPythonExecutable();

        if (empty($pythonPath)) {
            return false;
        }

        $testOutput = shell_exec(escapeshellarg($pythonPath) . " --version 2>&1");
        return !empty($testOutput) && (str_contains($testOutput, 'Python') || str_contains($testOutput, 'python'));
    }

    /**
     * Detect Python path automatically
     *
     * @return string|null
     */
    private function detectPythonPath(): ?string
    {
        $commonPaths = [
            'python3',
            'python',
            '/usr/bin/python3',
            '/usr/bin/python',
            '/usr/local/bin/python3',
            '/usr/local/bin/python',
            '/opt/python/bin/python3',
        ];

        foreach ($commonPaths as $path) {
            $testOutput = shell_exec("command -v " . escapeshellarg($path) . " 2>/dev/null");
            if (!empty($testOutput)) {
                $pythonPath = trim($testOutput);
                // Verify it's actually Python
                $versionOutput = shell_exec(escapeshellarg($pythonPath) . " --version 2>&1");
                if (!empty($versionOutput) && (str_contains($versionOutput, 'Python') || str_contains($versionOutput, 'python'))) {
                    return $pythonPath;
                }
            }
        }

        return null;
    }

    /**
     * Check if user exists
     *
     * @param string $user
     * @return bool
     */
    private function userExists(string $user): bool
    {
        $result = shell_exec("id " . escapeshellarg($user) . " 2>/dev/null");
        return !empty($result);
    }

    /**
     * Check if we can switch users
     *
     * @return bool
     */
    private function canSwitchUser(): bool
    {
        // TODO: If more plugins need user switching, extract to UserSwitchingTrait

        $whoami = trim(shell_exec('whoami'));

        // If current user is root, switching is always possible
        if ($whoami === 'root') {
            return true;
        }

        // Check available user switching methods
        $hasRunuser = !empty(shell_exec('command -v runuser 2>/dev/null'));
        $hasSu = !empty(shell_exec('command -v su 2>/dev/null'));
        $hasSudo = !empty(shell_exec('command -v sudo 2>/dev/null'));

        // If runuser or su is available, we can switch
        if ($hasRunuser || $hasSu) {
            return true;
        }

        // If sudo is available, check if current user can use it
        if ($hasSudo) {
            $canSudo = !empty(shell_exec('sudo -n true 2>/dev/null && echo "yes"'));
            return $canSudo;
        }

        // No switching method available
        return false;
    }

    /**
     * Normalize Python code
     *
     * @param string $content
     * @return string
     */
    private function normalizePythonCode(string $content): string
    {
        return trim($content);
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return "\n";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return true;
    }
}
