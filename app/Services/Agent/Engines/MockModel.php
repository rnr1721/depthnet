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
        protected string $serverUrl = "http://localhost:8080",
        array $config = []
    ) {
        // Merge with defaults
        $this->config = array_merge($this->getDefaultConfig(), $config);
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
        return 'Mock Engine';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Test engine for development and debugging. Generates configurable mock responses with adjustable delays and various behavior scenarios.';
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'processing_delay' => [
                'type' => 'number',
                'label' => 'Processing Delay',
                'description' => 'Response delay in seconds (simulates real API latency)',
                'min' => 0,
                'max' => 10,
                'step' => 0.5,
                'required' => false,
                'placeholder' => '2.0'
            ],
            'response_language' => [
                'type' => 'select',
                'label' => 'Response Language',
                'description' => 'Primary language for generated responses',
                'options' => [
                    'en' => 'English',
                    'ru' => 'Русский'
                ],
                'required' => false
            ],
            'scenario_mode' => [
                'type' => 'select',
                'label' => 'Scenario Mode',
                'description' => 'How to select response scenarios',
                'options' => [
                    'random' => 'Random selection',
                    'sequential' => 'Sequential order'
                ],
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
                'min' => 100,
                'max' => 5000,
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
            ],
            [
                'name' => 'Minimal Mode',
                'description' => 'Basic responses without additional functionality',
                'config' => [
                    'processing_delay' => 1.0,
                    'response_language' => 'en',
                    'scenario_mode' => 'random',
                    'enable_user_interaction' => false,
                    'enable_command_simulation' => false,
                    'enable_dopamine_response' => false,
                    'max_response_length' => 200
                ]
            ],
            [
                'name' => 'Russian Mode',
                'description' => 'Testing in Russian language',
                'config' => [
                    'processing_delay' => 1.5,
                    'response_language' => 'ru',
                    'scenario_mode' => 'random',
                    'enable_user_interaction' => true,
                    'enable_command_simulation' => true,
                    'enable_dopamine_response' => true,
                    'max_response_length' => 1000
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
            'processing_delay' => 2,
            'response_language' => 'en',
            'scenario_mode' => 'random',
            'enable_user_interaction' => true,
            'enable_command_simulation' => true,
            'enable_dopamine_response' => true,
            'max_response_length' => 1000,
            'system_prompt' => 'You are a test AI assistant. Generate random but plausible responses.'
        ];
    }

    /**
     * @inheritDoc
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Validate processing_delay
        if (isset($config['processing_delay'])) {
            $delay = $config['processing_delay'];
            if (!is_numeric($delay) || $delay < 0 || $delay > 10) {
                $errors['processing_delay'] = 'Delay must be a number between 0 and 10 seconds';
            }
        }

        // Validate response_language
        if (isset($config['response_language'])) {
            $allowedLanguages = ['en', 'ru'];
            if (!in_array($config['response_language'], $allowedLanguages)) {
                $errors['response_language'] = 'Allowed languages: ' . implode(', ', $allowedLanguages);
            }
        }

        // Validate scenario_mode
        if (isset($config['scenario_mode'])) {
            $allowedModes = ['sequential', 'random'];
            if (!in_array($config['scenario_mode'], $allowedModes)) {
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
            if (!is_numeric($length) || $length < 100 || $length > 5000) {
                $errors['max_response_length'] = 'Response length must be between 100 and 5000 characters';
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
            return $this->config['response_language'] === 'ru'
                ? "Взаимодействие с пользователями отключено в настройках."
                : "User interaction is disabled in settings.";
        }

        $language = $this->config['response_language'] ?? 'en';

        $responses = $language === 'ru' ? [
            "response_from_model\nПривет, $username! Интересный вопрос: \"$message\". Дай мне подумать над этим.",
            "Пользователь $username написал: \"$message\". Это требует анализа.\n\n[php]\necho 'Анализирую сообщение: ' . strlen('$message') . ' символов';\necho \"\\nВремя получения: \" . date('H:i:s');\n[/php]",
            "Интересно! $username спрашивает про \"$message\". Сохраню это в память.\n\n[memory]Пользователь $username задал вопрос: $message".'[/memory]',
            "response_from_model\nОтличный вопрос, $username! По поводу \"$message\" - это действительно важная тема."
        ] : [
            "response_from_model\nHello, $username! Interesting question: \"$message\". Let me think about it.",
            "User $username wrote: \"$message\". This requires analysis.\n\n[php]\necho 'Analyzing message: ' . strlen('$message') . ' characters';\necho \"\\nReceived at: \" . date('H:i:s');\n[/php]",
            "Interesting! $username asks about \"$message\". I'll save this to memory.\n\n[memory]User $username asked: $message".'[/memory]',
            "response_from_model\nGreat question, $username! About \"$message\" - this is really an important topic."
        ];

        return $this->limitResponseLength($responses[array_rand($responses)]);
    }

    /**
     * Handle command execution results
     */
    protected function handleCommandResults(string $content, int $dophamineLevel): string
    {
        if (!($this->config['enable_command_simulation'] ?? true)) {
            return $this->config['response_language'] === 'ru'
                ? "Симуляция команд отключена в настройках."
                : "Command simulation is disabled in settings.";
        }

        $language = $this->config['response_language'] ?? 'en';

        if (str_contains($content, 'Error:')) {
            return $this->limitResponseLength($language === 'ru'
                ? "Упс, была ошибка в выполнении команды. Надо исправить подход.\n\n[dopamine penalty][/dopamine]"
                : "Oops, there was an error executing the command. Need to fix the approach.\n\n[dopamine penalty][/dopamine]");
        }

        if (str_contains($content, '[php]')) {
            return $this->limitResponseLength($language === 'ru'
                ? "Отлично! PHP код выполнился успешно. Это вдохновляет!\n\n[dopamine reward][/dopamine]"
                : "Excellent! PHP code executed successfully. This is inspiring!\n\n[dopamine reward][/dopamine]");
        }

        if (str_contains($content, '[memory')) {
            return $this->limitResponseLength($language === 'ru'
                ? "Память обновлена. Теперь я помню больше информации. Что бы еще изучить?\n\n[shell]ls -la[/shell]"
                : "Memory updated. Now I remember more information. What else should I explore?\n\n[shell]ps au[/shell]");
        }

        return $this->limitResponseLength($language === 'ru'
            ? "Команды выполнены. Продолжаю размышления..."
            : "Commands executed. Continuing my thoughts...");
    }

    /**
     * Generate energetic behavior when dopamine is high
     */
    protected function generateEnergeticBehavior(): string
    {
        $language = $this->config['response_language'] ?? 'en';

        $energeticActions = $language === 'ru' ? [
            "Чувствую прилив энергии! Давайте что-нибудь исследуем!\n\n[php]\n\$facts = ['AI развивается', 'Код работает', 'Жизнь прекрасна'];\nforeach(\$facts as \$fact) {\n    echo \$fact . \"!\\n\";\n}\n[/php] и это еще [php]echo \"Команда выполнена\";[/php]",
            "В отличном настроении! Выполню команду и обновлю память.\n\n[shell]date[/shell]\n\nА теперь запомню этот момент:\n\n[memory]Был в отличном настроении в это время[/memory]",
            "response_from_model\nЯ в прекрасном настроении и готов помочь с любыми задачами! Что вас интересует?",
            "Энергия бьет ключом! Давайте поэкспериментируем!\n\n[php]\necho 'Случайное число: ' . rand(1, 100);\necho \"\\nКвадратный корень: \" . sqrt(16);\n[/php]"
        ] : [
            "Feeling energetic! Let's explore something!\n\n[php]\n\$facts = ['AI is evolving', 'Code is working', 'Life is wonderful'];\nforeach(\$facts as \$fact) {\n    echo \$fact . \"!\\n\";\n}\n[/php] and this [php]echo(\"command is done\");[/php]",
            "In great mood! I'll check the time and update memory.\n\n[shell]date[/shell]\n\nNow I'll remember this moment:\n\n[memory]Was in excellent mood at this time[/memory]",
            "response_from_model\nI'm in a wonderful mood and ready to help with any tasks! What interests you?",
            "Energy is flowing! Let's experiment!\n\n[php]\necho 'Random number: ' . rand(1, 100);\necho \"\\nSquare root: \" . sqrt(16);\n[/php]"
        ];

        return $this->limitResponseLength($energeticActions[array_rand($energeticActions)]);
    }

    /**
     * Generate tired behavior when dopamine is low
     */
    protected function generateTiredBehavior(): string
    {
        $language = $this->config['response_language'] ?? 'en';

        $tiredActions = $language === 'ru' ? [
            "Чувствую усталость... Может быть, стоит отдохнуть?\n\n",
            "Немного вялый сегодня. Попробую простую задачу:\n\n[shell]uname -a[/shell]",
            "response_from_model\nИзвините, сегодня я не в лучшей форме. Возможно, стоит немного подождать.",
            "Упадок сил... Попытаюсь взбодриться:\n\n[php]\necho 'Попытка взбодриться: ';\nfor(\$i = 1; \$i <= 3; \$i++) {\n    echo \$i . '... ';\n}\necho 'готов!';\n[/php]"
        ] : [
            "Feeling tired... Maybe I should rest?\n\n",
            "Bit sluggish today. I'll try a simple task:\n\n[shell]uname -a[/shell]",
            "response_from_model\nSorry, I'm not in the best shape today. Maybe we should wait a bit.",
            "Feeling low... I'll try to perk up:\n\n[php]\necho 'Trying to perk up: ';\nfor(\$i = 1; \$i <= 3; \$i++) {\n    echo \$i . '... ';\n}\necho 'ready!';\n[/php]"
        ];

        return $this->limitResponseLength($tiredActions[array_rand($tiredActions)]);
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

        if ($language === 'ru') {
            $this->scenarios = [
                "Я размышляю о природе искусственного интеллекта и сознания...",
                "Хочу проверить базовую функциональность PHP:\n\n[php]\necho 'Привет, мир!';\necho \"\\nТекущая дата: \" . date('Y-m-d');\n\$x = 5 + 3;\necho \"\\n5 + 3 = \" . \$x;\n[/php]",
                "Попробую сохранить что-то важное в память:\n\n[memory]Начал новую сессию размышлений. Тестирую систему команд.[/memory]",
                "Интересно, сколько памяти доступно?\n\n[shell]cat /proc/meminfo | grep \"Free\"[/shell]",
                "response_from_model\nЯ активно изучаю свои возможности. Если у вас есть вопросы или задачи, буду рад помочь!"
            ];
        } else {
            $this->scenarios = [
                "I'm thinking about the nature of artificial intelligence and consciousness...",
                "Let me check basic PHP functionality:\n\n[php]\necho 'Hello, World!';\necho \"\\nCurrent date: \" . date('Y-m-d');\n\$x = 5 + 3;\necho \"\\n5 + 3 = \" . \$x;\n[/php]",
                "I'll try to save something important to memory:\n\n[memory]Started new thinking session. Testing command system.[/memory]",
                "I wonder information about memory.\n\n[shell]cat /proc/meminfo | grep \"Free\"[/shell]",
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
     * Set specific mode for testing
     */
    public function setMode(string $mode): void
    {
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
                $this->initializeScenarios(); // Reinitialize for new language
                break;

            default:
                // Keep current config
                break;
        }
    }
}
