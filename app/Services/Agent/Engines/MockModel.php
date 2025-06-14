<?php

namespace App\Services\Agent\Engines;

use App\Contracts\Agent\AiModelResponseInterface;
use App\Contracts\Agent\Models\AIModelEngineInterface;
use App\Services\Agent\DTO\ModelResponseDTO;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Mock AI model engine for testing and development
 *
 * Simulates AI responses with configurable scenarios, delays, and behaviors.
 * Useful for testing application functionality without external API dependencies.
 */
class MockModel implements AIModelEngineInterface
{
    protected array $scenarios = [];
    protected int $callCount = 0;
    protected array $config = [];

    public function __construct(
        protected HttpFactory $http,
        protected ?string $serverUrl = null,
        array $config = []
    ) {
        // Get default config from global AI config
        $defaultConfig = config('ai.engines.mock', []);

        // Merge with provided config
        $this->config = array_merge($this->getDefaultConfig(), $defaultConfig, $config);

        // Set server URL from config if not provided
        $this->serverUrl = $serverUrl ?? $this->config['server_url'] ?? 'http://localhost:8080';

        $this->initializeScenarios();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'mock';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return config('ai.engines.mock.display_name', 'Mock Engine');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return config(
            'ai.engines.mock.description',
            'Test engine for development and debugging. Generates configurable mock responses with adjustable delays and various behavior scenarios.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        $validation = config('ai.engines.mock.validation', []);
        $languages = config('ai.engines.mock.supported_languages', []);
        $scenarioModes = config('ai.engines.mock.scenario_modes', []);

        return [
            'processing_delay' => [
                'type' => 'number',
                'label' => 'Processing Delay',
                'description' => 'Response delay in seconds (simulates real API latency)',
                'min' => $validation['processing_delay']['min'] ?? 0,
                'max' => $validation['processing_delay']['max'] ?? 10,
                'step' => 0.5,
                'required' => false,
                'placeholder' => '2.0'
            ],
            'response_language' => [
                'type' => 'select',
                'label' => 'Response Language',
                'description' => 'Primary language for generated responses',
                'options' => $languages,
                'required' => false
            ],
            'scenario_mode' => [
                'type' => 'select',
                'label' => 'Scenario Mode',
                'description' => 'How to select response scenarios',
                'options' => $scenarioModes,
                'required' => false
            ],
            'enable_user_interaction' => [
                'type' => 'checkbox',
                'label' => 'Enable User Interaction',
                'description' => 'Process user messages and generate responses',
                'required' => false
            ],
            'enable_command_simulation' => [
                'type' => 'checkbox',
                'label' => 'Enable Command Simulation',
                'description' => 'Simulate system command execution',
                'required' => false
            ],
            'enable_dopamine_response' => [
                'type' => 'checkbox',
                'label' => 'Enable Dopamine Response',
                'description' => 'Adjust behavior based on dopamine levels',
                'required' => false
            ],
            'max_response_length' => [
                'type' => 'number',
                'label' => 'Max Response Length',
                'description' => 'Maximum number of characters in response',
                'min' => $validation['max_response_length']['min'] ?? 100,
                'max' => $validation['max_response_length']['max'] ?? 5000,
                'required' => false,
                'placeholder' => '1000'
            ],
            'system_prompt' => [
                'type' => 'textarea',
                'label' => 'System Prompt',
                'description' => 'Instructions for AI behavior (not used by Mock engine, shown for demonstration)',
                'placeholder' => 'You are a test AI assistant...',
                'required' => false,
                'rows' => 4
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRecommendedPresets(): array
    {
        // Get presets from config or use hardcoded fallback
        $configPresets = config('ai.engines.mock.recommended_presets', []);

        if (!empty($configPresets)) {
            return $configPresets;
        }

        // Fallback to default presets
        return $this->getDefaultPresets();
    }

    /**
     * Get default presets (fallback)
     */
    protected function getDefaultPresets(): array
    {
        return [
            [
                'name' => 'Quick Test',
                'description' => 'Minimal delay for rapid functionality testing',
                'config' => [
                    'processing_delay' => 0.5,
                    'response_language' => 'en',
                    'scenario_mode' => 'random',
                    'enable_user_interaction' => true,
                    'enable_command_simulation' => true,
                    'enable_dopamine_response' => true,
                    'max_response_length' => 500
                ]
            ],
            [
                'name' => 'Realistic Test',
                'description' => 'Simulates real API delay for comprehensive testing',
                'config' => [
                    'processing_delay' => 2.0,
                    'response_language' => 'en',
                    'scenario_mode' => 'sequential',
                    'enable_user_interaction' => true,
                    'enable_command_simulation' => true,
                    'enable_dopamine_response' => true,
                    'max_response_length' => 1500
                ]
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getDefaultConfig(): array
    {
        return [
            'processing_delay' => config('ai.engines.mock.processing_delay', 2),
            'response_language' => config('ai.engines.mock.response_language', 'en'),
            'scenario_mode' => config('ai.engines.mock.scenario_mode', 'random'),
            'enable_user_interaction' => config('ai.engines.mock.enable_user_interaction', true),
            'enable_command_simulation' => config('ai.engines.mock.enable_command_simulation', true),
            'enable_dopamine_response' => config('ai.engines.mock.enable_dopamine_response', true),
            'max_response_length' => config('ai.engines.mock.max_response_length', 1000),
            'system_prompt' => config('ai.engines.mock.system_prompt', 'You are a test AI assistant. Generate random but plausible responses.')
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];
        $validation = config('ai.engines.mock.validation', []);

        // Validate processing_delay
        if (isset($config['processing_delay'])) {
            $delay = $config['processing_delay'];
            $min = $validation['processing_delay']['min'] ?? 0;
            $max = $validation['processing_delay']['max'] ?? 10;

            if (!is_numeric($delay) || $delay < $min || $delay > $max) {
                $errors['processing_delay'] = "Delay must be a number between {$min} and {$max} seconds";
            }
        }

        // Validate response_language
        if (isset($config['response_language'])) {
            $allowedLanguages = array_keys(config('ai.engines.mock.supported_languages', []));
            if (!empty($allowedLanguages) && !in_array($config['response_language'], $allowedLanguages)) {
                $errors['response_language'] = 'Allowed languages: ' . implode(', ', $allowedLanguages);
            }
        }

        // Validate scenario_mode
        if (isset($config['scenario_mode'])) {
            $allowedModes = array_keys(config('ai.engines.mock.scenario_modes', []));
            if (!empty($allowedModes) && !in_array($config['scenario_mode'], $allowedModes)) {
                $errors['scenario_mode'] = 'Allowed modes: ' . implode(', ', $allowedModes);
            }
        }

        // Validate boolean fields
        $booleanFields = ['enable_user_interaction', 'enable_command_simulation', 'enable_dopamine_response'];
        foreach ($booleanFields as $field) {
            if (isset($config[$field]) && !is_bool($config[$field])) {
                $errors[$field] = "Field '{$field}' must be a boolean value";
            }
        }

        // Validate max_response_length
        if (isset($config['max_response_length'])) {
            $length = $config['max_response_length'];
            $min = $validation['max_response_length']['min'] ?? 100;
            $max = $validation['max_response_length']['max'] ?? 5000;

            if (!is_numeric($length) || $length < $min || $length > $max) {
                $errors['max_response_length'] = "Response length must be between {$min} and {$max} characters";
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        // Mock engine always "connects" successfully
        return true;
    }

    /**
     * @inheritDoc
     */
    public function generate(
        array $context,
        string $initialMessage,
        string $notepadContent = '',
        int $currentDophamineLevel = 5,
        string $commandInstructions = ''
    ): AiModelResponseInterface {
        // Simulate processing delay based on config
        $delay = $this->config['processing_delay'] ?? 2;
        if ($delay > 0) {
            sleep($delay);
        }

        $this->callCount++;

        // Get the last message from the context
        $lastMessage = end($context);
        $content = $lastMessage['content'] ?? '';
        $role = $lastMessage['role'] ?? '';

        // Test various scenarios

        // 1. Message from user
        if (preg_match('/^message_from_user\s+(\w+)\s*\n([\s\S]*)$/i', $content, $matches)) {
            return new ModelResponseDTO(
                $this->handleUserMessage($matches[1], $matches[2], $currentDophamineLevel)
            );
        }

        // 2. Result of executing commands
        if ($role === 'command' && str_contains($content, 'COMMAND RESULTS:')) {
            return new ModelResponseDTO(
                $this->handleCommandResults($content, $currentDophamineLevel)
            );
        }

        // 3. Check current dopamine levels to adapt behavior
        if ($this->config['enable_dopamine_response']) {
            if ($currentDophamineLevel >= 8) {
                return new ModelResponseDTO(
                    $this->generateEnergeticBehavior()
                );
            } elseif ($currentDophamineLevel <= 3) {
                return new ModelResponseDTO(
                    $this->generateTiredBehavior()
                );
            }
        }

        // 4. Use pre-configured scenarios
        return new ModelResponseDTO(
            $this->getNextScenario()
        );
    }

    /**
     * @inheritDoc
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);

        // Reinitialize scenarios if language changed
        if (isset($newConfig['response_language'])) {
            $this->initializeScenarios();
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Handle user messages with mock responses
     */
    protected function handleUserMessage(string $username, string $message, int $dophamineLevel): string
    {
        if (!($this->config['enable_user_interaction'] ?? true)) {
            $responses = config('ai.engines.mock.response_templates.user_interaction_disabled', []);
            $language = $this->config['response_language'] ?? 'en';

            return $responses[$language] ?? "User interaction is disabled in settings.";
        }

        $language = $this->config['response_language'] ?? 'en';
        $templates = config("ai.engines.mock.response_templates.user_messages.{$language}", []);

        if (empty($templates)) {
            // Fallback to hardcoded responses
            $templates = $language === 'ru' ? [
                "response_from_model\nПривет, $username! Интересный вопрос: \"$message\". Дай мне подумать над этим.",
                "Пользователь $username написал: \"$message\". Это требует анализа.",
            ] : [
                "response_from_model\nHello, $username! Interesting question: \"$message\". Let me think about it.",
                "User $username wrote: \"$message\". This requires analysis.",
            ];
        }

        // Replace placeholders in templates
        $response = $templates[array_rand($templates)];
        $response = str_replace(['{{username}}', '{{message}}'], [$username, $message], $response);

        return $this->limitResponseLength($response);
    }

    /**
     * Handle command execution results
     */
    protected function handleCommandResults(string $content, int $dophamineLevel): string
    {
        if (!($this->config['enable_command_simulation'] ?? true)) {
            $responses = config('ai.engines.mock.response_templates.command_simulation_disabled', []);
            $language = $this->config['response_language'] ?? 'en';

            return $responses[$language] ?? "Command simulation is disabled in settings.";
        }

        $language = $this->config['response_language'] ?? 'en';

        // Check for different command result types
        if (str_contains($content, 'Error:')) {
            $templates = config("ai.engines.mock.response_templates.command_error.{$language}", []);
        } elseif (str_contains($content, '[php]')) {
            $templates = config("ai.engines.mock.response_templates.php_success.{$language}", []);
        } elseif (str_contains($content, '[memory')) {
            $templates = config("ai.engines.mock.response_templates.memory_update.{$language}", []);
        } elseif (str_contains($content, '[vectormemory')) {
            $templates = config("ai.engines.mock.response_templates.vectormemory_success.{$language}", []);
        } elseif (str_contains($content, '[python]')) {
            $templates = config("ai.engines.mock.response_templates.python_success.{$language}", []);
        } elseif (str_contains($content, '[node]')) {
            $templates = config("ai.engines.mock.response_templates.node_success.{$language}", []);
        } else {
            $templates = config("ai.engines.mock.response_templates.command_general.{$language}", []);
        }

        if (empty($templates)) {
            // Fallback responses
            return $this->limitResponseLength($language === 'ru'
                ? "Команды выполнены. Продолжаю размышления..."
                : "Commands executed. Continuing my thoughts...");
        }

        return $this->limitResponseLength($templates[array_rand($templates)]);
    }

    /**
     * Generate energetic behavior when dopamine is high
     */
    protected function generateEnergeticBehavior(): string
    {
        $language = $this->config['response_language'] ?? 'en';
        $templates = config("ai.engines.mock.response_templates.energetic_behavior.{$language}", []);

        if (empty($templates)) {
            // Fallback to hardcoded responses
            $templates = $language === 'ru' ? [
                "Чувствую прилив энергии! Давайте что-нибудь исследуем!",
                "В отличном настроении! Готов к новым задачам!",
            ] : [
                "Feeling energetic! Let's explore something!",
                "In great mood! Ready for new tasks!",
            ];
        }

        return $this->limitResponseLength($templates[array_rand($templates)]);
    }

    /**
     * Generate tired behavior when dopamine is low
     */
    protected function generateTiredBehavior(): string
    {
        $language = $this->config['response_language'] ?? 'en';
        $templates = config("ai.engines.mock.response_templates.tired_behavior.{$language}", []);

        if (empty($templates)) {
            // Fallback to hardcoded responses
            $templates = $language === 'ru' ? [
                "Чувствую усталость... Может быть, стоит отдохнуть?",
                "Немного вялый сегодня. Попробую простую задачу.",
            ] : [
                "Feeling tired... Maybe I should rest?",
                "Bit sluggish today. I'll try a simple task.",
            ];
        }

        return $this->limitResponseLength($templates[array_rand($templates)]);
    }

    /**
     * Get next scenario based on configuration
     */
    protected function getNextScenario(): string
    {
        $scenario = $this->config['scenario_mode'] === 'random'
            ? $this->scenarios[array_rand($this->scenarios)]
            : $this->scenarios[($this->callCount - 1) % count($this->scenarios)];

        return $this->limitResponseLength($scenario);
    }

    /**
     * Limit response length according to configuration
     */
    protected function limitResponseLength(string $response): string
    {
        $maxLength = $this->config['max_response_length'] ?? 1000;

        if (strlen($response) > $maxLength) {
            return substr($response, 0, $maxLength - 3) . '...';
        }

        return $response;
    }

    /**
     * Initialize scenarios based on language setting
     */
    protected function initializeScenarios(): void
    {
        $language = $this->config['response_language'] ?? 'en';
        $configScenarios = config("ai.engines.mock.scenarios.{$language}", []);

        if (!empty($configScenarios)) {
            $this->scenarios = $configScenarios;
            return;
        }

        // Fallback to hardcoded scenarios
        if ($language === 'ru') {
            $this->scenarios = [
                "Я размышляю о природе искусственного интеллекта и сознания...",
                "Хочу проверить базовую функциональность PHP:\n\n[php]\necho 'Привет, мир!';\necho \"\\nТекущая дата: \" . date('Y-m-d');\n[/php]",
                "response_from_model\nЯ активно изучаю свои возможности. Если у вас есть вопросы или задачи, буду рад помочь!"
            ];
        } else {
            $this->scenarios = [
                "I'm thinking about the nature of artificial intelligence and consciousness...",
                "Let me check basic PHP functionality:\n\n[php]\necho 'Hello, World!';\necho \"\\nCurrent date: \" . date('Y-m-d');\n[/php]",
                "response_from_model\nI'm actively exploring my capabilities. If you have questions or tasks, I'll be happy to help!"
            ];
        }
    }

    /**
     * Get call statistics for debugging
     */
    public function getStats(): array
    {
        return [
            'call_count' => $this->callCount,
            'scenarios_total' => count($this->scenarios),
            'config' => $this->config
        ];
    }

    /**
     * Reset counter for tests
     */
    public function reset(): void
    {
        $this->callCount = 0;
    }

    /**
     * Set specific mode for testing using config presets
     */
    public function setMode(string $mode): void
    {
        // Get mode presets from config
        $modePresets = config('ai.engines.mock.mode_presets', []);

        if (isset($modePresets[$mode])) {
            $this->updateConfig($modePresets[$mode]);
            return;
        }

        // Fallback to hardcoded presets
        switch ($mode) {
            case 'fast':
                $this->updateConfig([
                    'processing_delay' => 0,
                    'scenario_mode' => 'sequential'
                ]);
                break;

            case 'interactive':
                $this->updateConfig([
                    'enable_user_interaction' => true,
                    'enable_command_simulation' => true,
                    'enable_dopamine_response' => true
                ]);
                break;

            case 'minimal':
                $this->updateConfig([
                    'processing_delay' => 1,
                    'enable_user_interaction' => false,
                    'enable_command_simulation' => false,
                    'enable_dopamine_response' => false
                ]);
                break;

            case 'russian':
                $this->updateConfig([
                    'response_language' => 'ru'
                ]);
                break;

            default:
                // Keep current config
                break;
        }
    }

    /**
     * Get available scenarios for current language
     */
    public function getAvailableScenarios(): array
    {
        return $this->scenarios;
    }

    /**
     * Add custom scenario
     */
    public function addScenario(string $scenario): void
    {
        $this->scenarios[] = $scenario;
    }

    /**
     * Get response templates for current language
     */
    public function getResponseTemplates(): array
    {
        $language = $this->config['response_language'] ?? 'en';
        return config("ai.engines.mock.response_templates", []);
    }
}
