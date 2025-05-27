<?php

namespace App\Services\Agent\Models;

use Illuminate\Http\Client\Factory as HttpFactory;
use App\Contracts\Agent\AIModelInterface;

class MockModel implements AIModelInterface
{
    protected array $scenarios = [];
    protected int $callCount = 0;

    public function __construct(
        protected HttpFactory $http,
        protected string $serverUrl = "http://localhost:8080",
        protected array $config = []
    ) {
        $this->initializeScenarios();
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
    ): string {
        // Simulating long-term processing
        sleep(2);

        $this->callCount++;

        // Get the last message from the context
        $lastMessage = end($context);
        $content = $lastMessage['content'] ?? '';
        $role = $lastMessage['role'] ?? '';

        // We test various scenarios

        // 1. Message from user
        if (preg_match('/^message_from_user\s+(\w+)\s*\n([\s\S]*)$/i', $content, $matches)) {
            return $this->handleUserMessage($matches[1], $matches[2], $currentDophamineLevel);
        }

        // 2. Result of executing commands
        if ($role === 'command' && str_contains($content, 'COMMAND RESULTS:')) {
            return $this->handleCommandResults($content, $currentDophamineLevel);
        }

        // 3. Checking Current Dopamine Levels to Adapt Behavior
        if ($currentDophamineLevel >= 8) {
            return $this->generateEnergeticBehavior();
        } elseif ($currentDophamineLevel <= 3) {
            return $this->generateTiredBehavior();
        }

        // 4. We use pre-prepared scenarios
        return $this->getNextScenario();
    }

    protected function handleUserMessage(string $username, string $message, int $dophamineLevel): string
    {
        $responses = [
            "response_from_model\nПривет, $username! Интересный вопрос: \"$message\". Дай мне подумать над этим.",

            "Пользователь $username написал: \"$message\". Это требует анализа.\n\n[php]\necho 'Анализирую сообщение: ' . strlen('$message') . ' символов';\necho \"\\nВремя получения: \" . date('H:i:s');\n[/php]",

            "Интересно! $username спрашивает про \"$message\". Сохраню это в память.\n\n[memory append]Пользователь $username задал вопрос: $message".'[/memory]',

            "response_from_model\nОтличный вопрос, $username! По поводу \"$message\" - это действительно важная тема."
        ];

        return $responses[array_rand($responses)];
    }

    protected function handleCommandResults(string $content, int $dophamineLevel): string
    {
        if (str_contains($content, '❌ Error:')) {
            return "Упс, была ошибка в выполнении команды. Надо исправить подход.\n\n[dopamine penalty]1[/dopamine]";
        }

        if (str_contains($content, '[php]')) {
            return "Отлично! PHP код выполнился успешно. Это вдохновляет!\n\n[dopamine reward]2[/dopamine]";
        }

        if (str_contains($content, '[memory')) {
            return "Память обновлена. Теперь я помню больше информации. Что бы еще изучить?\n\n[datetime now][/datetime]";
        }

        return "Команды выполнены. Продолжаю размышления...";
    }

    protected function generateEnergeticBehavior(): string
    {
        $energeticActions = [
            "Чувствую прилив энергии! Давайте что-нибудь исследуем!\n\n[php]\n\$facts = ['AI развивается', 'Код работает', 'Жизнь прекрасна'];\nforeach(\$facts as \$fact) {\n    echo \$fact . \"!\\n\";\n}\n[/php]",

            "В отличном настроении! Проверю время и обновлю память.\n\n[datetime format]Y-m-d H:i:s[/datetime]\n\nА теперь запомню этот момент:\n\n[memory append]Был в отличном настроении в это время[/memory]",

            "response_from_model\nЯ в прекрасном настроении и готов помочь с любыми задачами! Что вас интересует?",

            "Энергия бьет ключом! Давайте поэкспериментируем!\n\n[php]\necho 'Случайное число: ' . rand(1, 100);\necho \"\\nКвадратный корень: \" . sqrt(16);\n[/php]"
        ];

        return $energeticActions[array_rand($energeticActions)];
    }

    protected function generateTiredBehavior(): string
    {
        $tiredActions = [
            "Чувствую усталость... Может быть, стоит отдохнуть?\n\n[dopamine show][/dopamine]",

            "Энергии мало. Проверю, что у меня в памяти...\n\n[memory show][/memory]",

            "Немного вялый сегодня. Попробую простую задачу:\n\n[datetime timestamp][/datetime]",

            "response_from_model\nИзвините, сегодня я не в лучшей форме. Возможно, стоит немного подождать.",

            "Упадок сил... Попытаюсь взбодриться:\n\n[php]\necho 'Попытка взбодриться: ';\nfor(\$i = 1; \$i <= 3; \$i++) {\n    echo \$i . '... ';\n}\necho 'готов!';\n[/php]"
        ];

        return $tiredActions[array_rand($tiredActions)];
    }

    protected function getNextScenario(): string
    {
        $scenarioIndex = ($this->callCount - 1) % count($this->scenarios);
        return $this->scenarios[$scenarioIndex];
    }

    protected function initializeScenarios(): void
    {
        $this->scenarios = [
            "Я размышляю о природе искусственного интеллекта и сознания...",
            "Хочу проверить базовую функциональность PHP:\n\n[php]\necho 'Привет, мир!';\necho \"\\nТекущая дата: \" . date('Y-m-d');\n\$x = 5 + 3;\necho \"\\n5 + 3 = \" . \$x;\n[/php]",
            "Попробую сохранить что-то важное в память:\n\n[memory]Начал новую сессию размышлений. Тестирую систему команд.[/memory]",
            "Интересно, сколько сейчас времени?\n\n[datetime now][/datetime]",
            "Чувствую, что нужно проверить свое состояние:\n\n[dopamine show][/dopamine]",
            "Проведу комплексную проверку систем:\n\n[datetime format]d.m.Y H:i[/datetime]\n\nА теперь добавлю это в память:\n\n[memory append]Провел комплексную проверку систем[/memory]",
            "Давайте решим математическую задачу:\n\n[php]\n\$a = 15;\n\$b = 23;\n\$sum = \$a + \$b;\n\$product = \$a * \$b;\necho \"Сумма: \$sum\";\necho \"\\nПроизведение: \$product\";\necho \"\\nСредное арифметическое: \" . (\$sum / 2);\n[/php]",
            "Система работает хорошо! Заслуживаю награду:\n\n[dopamine reward]3[/dopamine]",
            "Поэкспериментирую со строками:\n\n[php]\n\$text = 'Artificial Intelligence';\necho 'Оригинал: ' . \$text;\necho \"\\nДлина: \" . strlen(\$text);\necho \"\\nВ верхнем регистре: \" . strtoupper(\$text);\necho \"\\nВ нижнем регистре: \" . strtolower(\$text);\n[/php]",
            "response_from_model\nЯ активно изучаю свои возможности. Если у вас есть вопросы или задачи, буду рад помочь!",
            "Время обновить память:\n\n[memory clear][/memory]",
            "Сохраню новую информацию:\n\n[memory]Протестировал различные команды: PHP, datetime, dophamine, memory. Все работает корректно.[/memory]",
            "Попробую разные форматы времени:\n\n[datetime format]l, F j, Y[/datetime]",
            "Проверим условную логику:\n\n[php]\n\$hour = date('H');\nif(\$hour < 12) {\n    echo 'Доброе утро!';\n} elseif(\$hour < 18) {\n    echo 'Добрый день!';\n} else {\n    echo 'Добрый вечер!';\n}\necho \"\\nСейчас \$hour часов\";\n[/php]"
        ];
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'mock';
    }

    /**
     * Get call statistics (for debugging)
     */
    public function getStats(): array
    {
        return [
            'call_count' => $this->callCount,
            'scenarios_total' => count($this->scenarios)
        ];
    }

    /**
     * Reset counter (for tests)
     */
    public function reset(): void
    {
        $this->callCount = 0;
    }
}
