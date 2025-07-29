<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;
use Psr\Log\LoggerInterface;

/**
 * PuppeteerBrowserPlugin class
 *
 * Advanced web browsing using Puppeteer via Node.js subprocess.
 * Provides full JavaScript support, page automation, screenshots,
 * and complex web interaction through Puppeteer's powerful API.
 */
class PuppeteerBrowserPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    private string $sessionId;
    private string $tempDir;
    private string $sessionFile;

    public function __construct(
        protected LoggerInterface $logger
    ) {
        $this->initializeConfig();
        $this->sessionId = uniqid('browser_');

        // Use storage directory instead of system temp
        $this->tempDir = storage_path('app/temp/browser/scripts');
        $sessionsDir = storage_path('app/temp/browser/sessions');
        $this->sessionFile = $sessionsDir . '/session_' . $this->sessionId . '.json';

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0755, true);
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'browser';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Advanced web browsing with Puppeteer. Full JavaScript support, screenshots, form automation, page interaction.';
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Open page: [browser]https://example.com[/browser]',
            'Click element: [browser click]button[/browser]',
            'Type in field: [browser type]{"selector":"input[name=email]","text":"test@example.com"}[/browser]',
            'Wait for element: [browser wait]#content[/browser]',
            'Take screenshot: [browser screenshot]page.png[/browser]',
            'Get current page content: [browser content][/browser]',
            'Get element text: [browser text]h1[/browser]',
            'Execute JavaScript: [browser eval]document.title[/browser]',
            'Get page title: [browser title][/browser]',
            'Get page URL: [browser url][/browser]',
            'Close browser: [browser close][/browser]'
        ];
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Browser operation completed successfully.";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error: Browser operation failed.";
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Browser Plugin',
                'description' => 'Allow web browsing with Puppeteer',
                'required' => false
            ],
            'headless' => [
                'type' => 'checkbox',
                'label' => 'Headless Mode',
                'description' => 'Run browser without GUI',
                'value' => true,
                'required' => false
            ],
            'viewport_width' => [
                'type' => 'number',
                'label' => 'Viewport Width',
                'description' => 'Browser viewport width in pixels',
                'min' => 800,
                'max' => 1920,
                'value' => 1280,
                'required' => false
            ],
            'viewport_height' => [
                'type' => 'number',
                'label' => 'Viewport Height',
                'description' => 'Browser viewport height in pixels',
                'min' => 600,
                'max' => 1080,
                'value' => 720,
                'required' => false
            ],
            'browser_timeout' => [
                'type' => 'number',
                'label' => 'Default Timeout (ms)',
                'description' => 'Default timeout for operations',
                'min' => 10000,
                'max' => 360000,
                'value' => 100000,
                'required' => false
            ],
            'user_agent' => [
                'type' => 'text',
                'label' => 'User Agent',
                'description' => 'Custom user agent string',
                'value' => 'DepthNet-Agent/1.0 (Puppeteer)',
                'required' => false
            ],
            'screenshot_path' => [
                'type' => 'text',
                'label' => 'Screenshot Directory',
                'description' => 'Directory to save screenshots (relative to storage/app)',
                'value' => 'screenshots',
                'required' => false
            ],
            'enable_javascript' => [
                'type' => 'checkbox',
                'label' => 'Enable JavaScript',
                'description' => 'Allow JavaScript execution',
                'value' => true,
                'required' => false
            ],
            'auto_screenshot' => [
                'type' => 'checkbox',
                'label' => 'Auto Screenshot',
                'description' => 'Automatically take screenshots after major actions',
                'value' => false,
                'required' => false
            ],
            'content_max_length' => [
                'type' => 'number',
                'label' => 'Max Content Length',
                'description' => 'Maximum characters to return in content (0 = no limit)',
                'min' => 0,
                'max' => 50000,
                'value' => 5000,
                'required' => false
            ],
            'include_head' => [
                'type' => 'checkbox',
                'label' => 'Include HEAD in content',
                'description' => 'Include <head> section in page content (usually not needed for AI)',
                'value' => false,
                'required' => false
            ],
            'allowed_domains' => [
                'type' => 'textarea',
                'label' => 'Allowed Domains',
                'description' => 'Comma-separated list of allowed domains (empty = all allowed)',
                'placeholder' => 'example.com, google.com',
                'required' => false
            ],
            'blocked_domains' => [
                'type' => 'textarea',
                'label' => 'Blocked Domains',
                'description' => 'Comma-separated list of blocked domains',
                'placeholder' => 'malicious-site.com',
                'required' => false
            ],
            'node_path' => [
                'type' => 'text',
                'label' => 'Node.js Path',
                'description' => 'Path to Node.js executable',
                'value' => 'node',
                'required' => false
            ],
            'puppeteer_path' => [
                'type' => 'text',
                'label' => 'Puppeteer Path',
                'description' => 'Custom path to Puppeteer module (leave empty for auto-detection)',
                'value' => '',
                'required' => false
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'headless' => true,
            'viewport_width' => 1280,
            'viewport_height' => 720,
            'browser_timeout' => 100000,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'screenshot_path' => 'screenshots',
            'enable_javascript' => true,
            'enable_images' => true,
            'auto_screenshot' => false,
            'content_max_length' => 5000,
            'include_head' => false,
            'allowed_domains' => '',
            'blocked_domains' => '',
            'node_path' => 'node',
            'puppeteer_path' => ''
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Check if Node.js is available
        if (isset($config['node_path'])) {
            $nodePath = $config['node_path'];
            $result = shell_exec("$nodePath --version 2>/dev/null");
            if (empty($result)) {
                $errors['node_path'] = "Node.js not found at path: $nodePath";
            }
        }

        return $errors;
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
            $this->ensurePuppeteerInstalled();

            $script = $this->createPuppeteerScript([
                'action' => 'test',
                'url' => 'data:text/html,<html><body><h1>Test</h1></body></html>'
            ]);

            $result = $this->executeNodeScript($script);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error("PuppeteerBrowserPlugin::testConnection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Browser plugin is disabled.";
        }

        $content = trim($content);

        // If empty content, show help
        if (empty($content)) {
            return "Usage:\n" .
                   "• Open page: [browser]https://example.com[/browser]\n" .
                   "• Actions: [browser click]selector[/browser], [browser screenshot][/browser]\n" .
                   "• Content: [browser content][/browser] for current page content";
        }

        // If it's a URL - open page and show content
        if ($this->isValidUrl($content)) {
            return $this->openPage($content);
        }

        // If not a URL, treat as help request
        return "Error: Invalid URL format. Use: [browser]https://example.com[/browser]\n" .
               "For actions on current page use: [browser click]selector[/browser], [browser screenshot][/browser], etc.";
    }

    /**
     * Open page and return navigation info + content
     */
    private function openPage(string $url): string
    {
        try {
            if (!$this->isDomainAllowed($url)) {
                return "Error: Domain not allowed by security policy.";
            }

            $this->ensurePuppeteerInstalled();

            $script = $this->createPuppeteerScript([
                'action' => 'open_page',
                'url' => $url,
                'timeout' => $this->config['browser_timeout'] ?? 30000,
                'includeHead' => $this->config['include_head'] ?? false
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Navigation failed');
            }

            // Create session for future operations
            file_put_contents($this->sessionFile, json_encode([
                'sessionId' => $this->sessionId,
                'started' => time(),
                'lastUrl' => $url,
                'simple_session' => true
            ]));

            // Format response with navigation info + content
            $response = "🌐 **Opened:** {$url}\n";
            $response .= "📄 **Title:** " . ($result['title'] ?? '[no title]') . "\n";
            $response .= "🔗 **Final URL:** " . ($result['finalUrl'] ?? $url) . "\n";

            // Add diagnostics if available
            if (isset($result['diagnostics'])) {
                $diag = $result['diagnostics'];
                $response .= "\n📊 **Page Info:**\n";
                $response .= "• Ready State: " . ($diag['readyState'] ?? 'unknown') . "\n";
                $response .= "• Body Elements: " . ($diag['bodyChildren'] ?? 0) . "\n";
                $response .= "• Scripts: " . ($diag['scripts'] ?? 0) . "\n";
                $response .= "• Has JS Framework: " . ($diag['hasJavaScript'] ? 'yes' : 'no') . "\n";
            }

            // Add content
            $maxLength = $this->config['content_max_length'] ?? 5000;
            $content = $result['content'] ?? '';

            if (!empty($content)) {
                $truncated = ($maxLength > 0) ? substr($content, 0, $maxLength) : $content;
                $suffix = ($maxLength > 0 && strlen($content) > $maxLength) ? '...[truncated]' : '';

                $response .= "\n📃 **Page Content:**\n{$truncated}{$suffix}";
            } else {
                $response .= "\n⚠️ **No content found** (page might be empty or failed to load)";
            }

            return $response;

        } catch (\Exception $e) {
            return "Error opening page: " . $e->getMessage();
        }
    }

    /**
     * Get page content
     */

    /**
     * Click element
     */
    public function click(string $selector): string
    {
        try {
            $script = $this->createPuppeteerScript([
                'action' => 'click',
                'selector' => $selector,
                'timeout' => $this->config['browser_timeout'] ?? 30000
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Click failed');
            }

            return "Successfully clicked element: {$selector}";
        } catch (\Exception $e) {
            return "Error clicking element: " . $e->getMessage();
        }
    }

    /**
     * Type text in field
     */
    public function type(string $jsonData): string
    {
        try {
            $data = json_decode($jsonData, true);
            if (!$data || !isset($data['selector']) || !isset($data['text'])) {
                return "Error: Invalid JSON. Required: {\"selector\":\"input\",\"text\":\"hello\"}";
            }

            $script = $this->createPuppeteerScript([
                'action' => 'type',
                'selector' => $data['selector'],
                'text' => $data['text'],
                'delay' => $data['delay'] ?? 50
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Type failed');
            }

            return "Successfully typed text in: {$data['selector']}";
        } catch (\Exception $e) {
            return "Error typing text: " . $e->getMessage();
        }
    }

    /**
     * Wait for element
     */
    public function wait(string $selector): string
    {
        try {
            $script = $this->createPuppeteerScript([
                'action' => 'wait',
                'selector' => $selector,
                'timeout' => $this->config['browser_timeout'] ?? 30000
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Wait timeout');
            }

            return "Element appeared: {$selector}";
        } catch (\Exception $e) {
            return "Error waiting for element: " . $e->getMessage();
        }
    }

    /**
     * Take screenshot
     */
    public function screenshot(string $filename = ''): string
    {
        try {
            if (empty($filename)) {
                $filename = 'screenshot_' . time() . '.png';
            }

            $screenshotPath = storage_path('app/' . $this->config['screenshot_path']);
            if (!is_dir($screenshotPath)) {
                mkdir($screenshotPath, 0755, true);
            }

            $fullPath = $screenshotPath . '/' . $filename;

            $script = $this->createPuppeteerScript([
                'action' => 'screenshot',
                'path' => $fullPath,
                'fullPage' => true
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Screenshot failed');
            }

            // Add live feedback for user
            $this->setPluginExecutionMeta('screenshot_taken', [
                'filename' => $filename,
                'path' => $fullPath,
                'url' => asset("storage/{$this->config['screenshot_path']}/{$filename}"),
                'timestamp' => now()->toISOString()
            ]);

            return "Screenshot saved: {$filename}\nPath: {$fullPath}\nView: " . asset("storage/{$this->config['screenshot_path']}/{$filename}");
        } catch (\Exception $e) {
            return "Error taking screenshot: " . $e->getMessage();
        }
    }

    /**
     * Start persistent browser session
     */
    public function start(string $content): string
    {
        try {
            if ($this->isSessionActive()) {
                return "Browser session already active. Use [browser stop] to close it first.";
            }

            $this->ensurePuppeteerInstalled();

            $script = $this->createPuppeteerScript([
                'action' => 'start_session',
                'sessionId' => $this->sessionId,
                'timeout' => $this->config['browser_timeout'] ?? 30000
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Failed to start session');
            }

            // Save session info
            file_put_contents($this->sessionFile, json_encode([
                'sessionId' => $this->sessionId,
                'started' => time(),
                'wsEndpoint' => $result['wsEndpoint'] ?? null
            ]));

            return "Browser session started successfully. You can now use [browser goto], [browser content], etc.";
        } catch (\Exception $e) {
            return "Error starting session: " . $e->getMessage();
        }
    }

    /**
     * Stop persistent browser session
     */
    public function stop(string $content): string
    {
        try {
            if (!$this->isSessionActive()) {
                return "No active browser session.";
            }

            $script = $this->createPuppeteerScript([
                'action' => 'stop_session',
                'sessionId' => $this->sessionId
            ]);

            $result = $this->executeNodeScript($script);

            // Remove session file
            if (file_exists($this->sessionFile)) {
                unlink($this->sessionFile);
            }

            return "Browser session stopped.";
        } catch (\Exception $e) {
            return "Error stopping session: " . $e->getMessage();
        }
    }

    /**
     * Get session status
     */
    public function status(string $content): string
    {
        try {
            if (!$this->isSessionActive()) {
                return "No active browser session. Use [browser start] to begin.";
            }

            $sessionData = json_decode(file_get_contents($this->sessionFile), true);

            $script = $this->createPuppeteerScript([
                'action' => 'session_status',
                'sessionId' => $this->sessionId
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Session appears to be broken. Use [browser stop] and [browser start] to restart.";
            }

            $uptime = time() - $sessionData['started'];
            return "Browser session active for " . gmdate("H:i:s", $uptime) . "\n" .
                "Current URL: " . ($result['url'] ?? 'none') . "\n" .
                "Title: " . ($result['title'] ?? 'none');
        } catch (\Exception $e) {
            return "Error checking status: " . $e->getMessage();
        }
    }

    /**
     * Check if session is active
     */
    private function isSessionActive(): bool
    {
        return file_exists($this->sessionFile);
    }

    public function content(string $selector = ''): string
    {
        try {
            // Check if we have session
            if (!$this->isSessionActive()) {
                return "Error: No active page. Open a page first: [browser]https://example.com[/browser]";
            }

            $sessionData = json_decode(file_get_contents($this->sessionFile), true);
            $url = $sessionData['lastUrl'];

            $script = $this->createPuppeteerScript([
                'action' => 'get_content',
                'url' => $url,
                'timeout' => $this->config['browser_timeout'] ?? 30000,
                'selector' => $selector ?: null,
                'includeHead' => $this->config['include_head'] ?? false
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Content extraction failed');
            }

            $maxLength = $this->config['content_max_length'] ?? 5000;
            $content = $result['content'] ?? '';
            $truncated = ($maxLength > 0) ? substr($content, 0, $maxLength) : $content;
            $suffix = ($maxLength > 0 && strlen($content) > $maxLength) ? '...[truncated]' : '';

            return ($selector ? "Element content ({$selector}):" : "Page content:") . "\n{$truncated}{$suffix}";

        } catch (\Exception $e) {
            return "Error getting content: " . $e->getMessage();
        }
    }

    /**
     * Navigate to URL (kept for backward compatibility, but simplified)
     */
    public function goto(string $url): string
    {
        return $this->openPage($url);
    }

    /**
     * Get element text
     */
    public function text(string $selector): string
    {
        try {
            $script = $this->createPuppeteerScript([
                'action' => 'text',
                'selector' => $selector
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'Text extraction failed');
            }

            return "Element text ({$selector}): " . ($result['text'] ?? '[empty]');
        } catch (\Exception $e) {
            return "Error getting text: " . $e->getMessage();
        }
    }

    /**
     * Execute JavaScript
     */
    public function eval(string $code): string
    {
        try {
            if (!$this->config['enable_javascript']) {
                return "Error: JavaScript execution is disabled.";
            }

            $script = $this->createPuppeteerScript([
                'action' => 'eval',
                'code' => $code
            ]);

            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error: " . ($result['error'] ?? 'JavaScript execution failed');
            }

            return "JavaScript result: " . json_encode($result['result']);
        } catch (\Exception $e) {
            return "Error executing JavaScript: " . $e->getMessage();
        }
    }

    /**
     * Get page title
     */
    public function title(string $content): string
    {
        try {
            $script = $this->createPuppeteerScript(['action' => 'title']);
            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error getting title: " . ($result['error'] ?? 'Unknown error');
            }

            return "Page title: " . ($result['title'] ?? '[no title]');
        } catch (\Exception $e) {
            return "Error getting title: " . $e->getMessage();
        }
    }

    /**
     * Get current URL
     */
    public function url(string $content): string
    {
        try {
            $script = $this->createPuppeteerScript(['action' => 'url']);
            $result = $this->executeNodeScript($script);

            if (!$result['success']) {
                return "Error getting URL: " . ($result['error'] ?? 'Unknown error');
            }

            return "Current URL: " . ($result['url'] ?? '[unknown]');
        } catch (\Exception $e) {
            return "Error getting URL: " . $e->getMessage();
        }
    }

    /**
     * Close browser
     */
    public function close(string $content): string
    {
        try {
            $script = $this->createPuppeteerScript(['action' => 'close']);
            $result = $this->executeNodeScript($script);

            return "Browser session closed.";
        } catch (\Exception $e) {
            return "Error closing browser: " . $e->getMessage();
        }
    }

    /**
     * Create Puppeteer script with given parameters
     */
    private function createPuppeteerScript(array $params): string
    {
        $config = [
            'headless' => $this->config['headless'] ?? true,
            'viewport' => [
                'width' => $this->config['viewport_width'] ?? 1280,
                'height' => $this->config['viewport_height'] ?? 720
            ],
            'userAgent' => $this->config['user_agent'] ?? 'DepthNet-Agent/1.0',
            'timeout' => $this->config['browser_timeout'] ?? 30000,
            'enableJavaScript' => $this->config['enable_javascript'] ?? true,
            'enableImages' => $this->config['enable_images'] ?? true
        ];

        $jsConfig = json_encode($config, JSON_PRETTY_PRINT);
        $jsParams = json_encode($params, JSON_PRETTY_PRINT);

        // Check if we should use ES modules or CommonJS
        $projectPath = base_path();
        $packageJsonPath = $projectPath . '/package.json';
        $useESModules = false;

        if (file_exists($packageJsonPath)) {
            $packageJson = json_decode(file_get_contents($packageJsonPath), true);
            $useESModules = isset($packageJson['type']) && $packageJson['type'] === 'module';
        }

        // Load template based on module type
        $templateFile = $useESModules ? 'puppeteer-esm.js' : 'puppeteer-commonjs.js';
        $templatePath = base_path('data/plugins/browser/' . $templateFile);

        if (!file_exists($templatePath)) {
            throw new \Exception("Browser script template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        // Replace placeholders in template
        $script = str_replace([
            '{{PROJECT_PATH}}',
            '{{CONFIG}}',
            '{{PARAMS}}'
        ], [
            $projectPath,
            $jsConfig,
            $jsParams
        ], $template);

        return $script;
    }

    /**
     * Execute Node.js script and parse result
     */
    private function executeNodeScript(string $script): array
    {
        try {
            // Determine the correct file extension based on module type
            $projectDir = base_path();
            $packageJsonPath = $projectDir . '/package.json';
            $useESModules = false;

            if (file_exists($packageJsonPath)) {
                $packageJson = json_decode(file_get_contents($packageJsonPath), true);
                $useESModules = isset($packageJson['type']) && $packageJson['type'] === 'module';
            }

            // Use appropriate extension
            $extension = $useESModules ? '.mjs' : '.cjs';

            // Use the organized temp directory (already created in constructor)
            $scriptDir = $this->tempDir;

            if (!is_writable($scriptDir)) {
                return ['success' => false, 'error' => "Directory not writable: {$scriptDir}"];
            }

            // Create unique script file path
            $scriptName = 'script_' . uniqid() . $extension;
            $scriptPath = $scriptDir . '/' . $scriptName;

            // Write script directly to filesystem
            $writeResult = file_put_contents($scriptPath, $script);

            if ($writeResult === false) {
                return ['success' => false, 'error' => "Failed to write script file: {$scriptPath}"];
            }

            // Verify file was created
            if (!file_exists($scriptPath)) {
                return ['success' => false, 'error' => "Script file was not created: {$scriptPath}"];
            }

            $nodePath = $this->config['node_path'] ?? 'node';

            // Run from project directory so node_modules can be found
            $command = "cd " . escapeshellarg($projectDir) . " && $nodePath " . escapeshellarg($scriptPath) . " 2>&1";

            $output = shell_exec($command);

            // Clean up script file
            if (file_exists($scriptPath)) {
                unlink($scriptPath);
            }

            if (empty($output)) {
                return ['success' => false, 'error' => 'No output from Node.js script'];
            }

            $result = json_decode(trim($output), true);
            if ($result === null) {
                return ['success' => false, 'error' => 'Invalid JSON response: ' . $output];
            }

            return $result;
        } catch (\Exception $e) {
            // Clean up on exception
            if (isset($scriptPath) && file_exists($scriptPath)) {
                unlink($scriptPath);
            }

            return ['success' => false, 'error' => 'Script execution error: ' . $e->getMessage()];
        }
    }

    /**
     * Ensure Puppeteer is installed
     */
    private function ensurePuppeteerInstalled(): void
    {
        $nodePath = $this->config['node_path'] ?? 'node';
        $projectDir = base_path();

        // Check if puppeteer exists in project node_modules
        $localPuppeteerPath = $projectDir . '/node_modules/puppeteer';

        if (is_dir($localPuppeteerPath)) {
            // Puppeteer found locally
            return;
        }

        // Test by running require from project directory
        $testScript = "try { require('puppeteer'); console.log('OK'); } catch(e) { console.log('ERROR: ' + e.message); }";
        $command = "cd " . escapeshellarg($projectDir) . " && $nodePath -e " . escapeshellarg($testScript);
        $result = shell_exec($command);

        if (!str_contains($result, 'OK')) {
            throw new \Exception("Puppeteer is not installed in project. Run: npm install puppeteer (from " . $projectDir . ")");
        }
    }

    /**
     * Validate URL
     */
    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check domain permissions
     */
    private function isDomainAllowed(string $url): bool
    {
        $domain = parse_url($url, PHP_URL_HOST);

        $blockedDomains = $this->getDomainsFromConfig('blocked_domains');
        if (in_array($domain, $blockedDomains)) {
            return false;
        }

        $allowedDomains = $this->getDomainsFromConfig('allowed_domains');
        if (!empty($allowedDomains)) {
            return in_array($domain, $allowedDomains);
        }

        return true;
    }

    /**
     * Parse domains from config
     */
    private function getDomainsFromConfig(string $configKey): array
    {
        $domainsString = $this->config[$configKey] ?? '';
        if (empty($domainsString)) {
            return [];
        }

        return array_map('trim', explode(',', $domainsString));
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
        $screenshotPath = storage_path('app/' . ($this->config['screenshot_path'] ?? 'screenshots'));
        if (!is_dir($screenshotPath)) {
            mkdir($screenshotPath, 0755, true);
        }
    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['content', 'title', 'url', 'close', 'start', 'stop', 'status'];
    }

    /**
     * Cleanup temp files
     */
    public function __destruct()
    {
        // Clean up any remaining temp script files
        if (is_dir($this->tempDir)) {
            $files = array_merge(
                glob($this->tempDir . '/script_*.js'),
                glob($this->tempDir . '/script_*.cjs'),
                glob($this->tempDir . '/script_*.mjs')
            );
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Clean up session file when object is destroyed
        if (file_exists($this->sessionFile)) {
            unlink($this->sessionFile);
        }
    }
}
