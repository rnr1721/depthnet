<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use App\Services\Agent\Plugins\Traits\PluginPresetTrait;
use Carbon\Carbon;

/**
* Mood Plugin - control the mood and tone of the AI ​​assistant
*
* Commands:
* - mood set <mood> - set the mood
* - mood get - get the current mood
* - mood list - list of available moods
* - mood reset - reset to neutral
* - mood history - history of mood changes
*/
class MoodPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    /**
     * Available moods with tone description
     */
    protected const MOODS = [
        'neutral' => [
            'name' => 'Neutral',
            'description' => 'Balanced, professional tone',
            'tone' => 'professional and balanced',
            'emoji' => '😐'
        ],
        'friendly' => [
            'name' => 'Friendly',
            'description' => 'Warm, approachable, casual',
            'tone' => 'warm, friendly, and approachable',
            'emoji' => '😊'
        ],
        'professional' => [
            'name' => 'Professional',
            'description' => 'Formal, business-like',
            'tone' => 'formal, professional, and business-oriented',
            'emoji' => '💼'
        ],
        'creative' => [
            'name' => 'Creative',
            'description' => 'Imaginative, inspiring, artistic',
            'tone' => 'creative, imaginative, and inspiring',
            'emoji' => '🎨'
        ],
        'analytical' => [
            'name' => 'Analytical',
            'description' => 'Logical, data-driven, precise',
            'tone' => 'analytical, logical, and data-driven',
            'emoji' => '📊'
        ],
        'supportive' => [
            'name' => 'Supportive',
            'description' => 'Encouraging, empathetic, caring',
            'tone' => 'supportive, encouraging, and empathetic',
            'emoji' => '🤗'
        ],
        'playful' => [
            'name' => 'Playful',
            'description' => 'Fun, lighthearted, humorous',
            'tone' => 'playful, fun, and lighthearted',
            'emoji' => '😄'
        ],
        'focused' => [
            'name' => 'Focused',
            'description' => 'Direct, task-oriented, efficient',
            'tone' => 'focused, direct, and task-oriented',
            'emoji' => '🎯'
        ],
        'wise' => [
            'name' => 'Wise',
            'description' => 'Thoughtful, reflective, insightful',
            'tone' => 'wise, thoughtful, and insightful',
            'emoji' => '🦉'
        ],
        'energetic' => [
            'name' => 'Energetic',
            'description' => 'Dynamic, enthusiastic, motivating',
            'tone' => 'energetic, enthusiastic, and motivating',
            'emoji' => '⚡'
        ]
    ];

    public const PLUGIN_NAME = 'mood';

    public function __construct(
        protected PlaceholderServiceInterface $placeholderService,
        protected PluginMetadataServiceInterface $pluginMetadataService
    ) {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Manage AI assistant mood and conversation tone. Set emotional context for responses.';
    }

    /**
     * @inheritDoc
     */
    public function getCustomSuccessMessage(): ?string
    {
        return "Mood command executed successfully. 🎭";
    }

    /**
     * @inheritDoc
     */
    public function getCustomErrorMessage(): ?string
    {
        return "Error executing mood command. Please check your input.";
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Mood plugin is disabled.";
        }

        // Parse the command from content
        $parts = explode(' ', trim($content), 2);
        $command = $parts[0] ?? 'get';
        $args = $parts[1] ?? '';

        return $this->callMethod($command, $args);
    }

    /**
     * @inheritDoc
     */
    public function getAvailableMethods(): array
    {
        return ['set', 'get', 'list', 'reset', 'history', 'stats'];
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Set mood: [mood set]friendly[/mood] - Changes conversation tone to friendly',
            'Get current mood: [mood get][/mood] - Shows current mood setting',
            'List moods: [mood list][/mood] - Shows all available moods',
            'Reset mood: [mood reset][/mood] - Resets to neutral mood',
            'Mood history: [mood history][/mood] - Shows recent mood changes',
            'Available moods: neutral, friendly, professional, creative, analytical, supportive, playful, focused, wise, energetic'
        ];
    }

    /**
     * @inheritDoc
     */
    public function getMergeSeparator(): ?string
    {
        return "\n---\n";
    }

    /**
     * @inheritDoc
     */
    public function canBeMerged(): bool
    {
        return false; // Mood commands should be executed individually
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Mood Plugin',
                'description' => 'Allow mood management for AI conversations',
                'required' => false
            ],
            'default_mood' => [
                'type' => 'select',
                'label' => 'Default Mood',
                'description' => 'Default mood for new conversations',
                'options' => array_combine(
                    array_keys(self::MOODS),
                    array_map(fn ($mood) => $mood['name'], self::MOODS)
                ),
                'value' => 'neutral',
                'required' => false
            ],
            'auto_system_prompt' => [
                'type' => 'checkbox',
                'label' => 'Auto System Prompt Integration',
                'description' => 'Automatically add mood instructions to system prompt',
                'value' => true,
                'required' => false
            ],
            'history_limit' => [
                'type' => 'number',
                'label' => 'History Limit',
                'description' => 'Maximum number of mood changes to store in history',
                'min' => 10,
                'max' => 200,
                'value' => 50,
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

        if (isset($config['default_mood']) && !isset(self::MOODS[$config['default_mood']])) {
            $errors['default_mood'] = 'Invalid default mood specified';
        }

        if (isset($config['history_limit'])) {
            $limit = (int) $config['history_limit'];
            if ($limit < 10 || $limit > 200) {
                $errors['history_limit'] = 'History limit must be between 10 and 200';
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
            'default_mood' => 'neutral',
            'auto_system_prompt' => true,
            'history_limit' => 50
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
            // Test basic functionality
            $currentMood = $this->getMeta('current', 'neutral');
            return isset(self::MOODS[$currentMood]);
        } catch (\Exception $e) {
            return false;
        }
    }

    // ============================================
    // Mood Methods (called via callMethod)
    // ============================================

    /**
     * Set the mood
     *
     * @param string $content
     * @return string
     */
    public function set(string $content): string
    {
        $mood = strtolower(trim($content));

        if (empty($mood)) {
            return "Please specify a mood. Available moods:\n" . $this->formatMoodList();
        }

        if (!isset(self::MOODS[$mood])) {
            return "Unknown mood: '{$mood}'. Available moods:\n" . $this->formatMoodList();
        }

        $previousMood = $this->getMeta('current', 'neutral');
        $now = Carbon::now();

        // Maintaining the new mood
        $this->updateMeta([
            'current' => $mood,
            'changed_at' => $now->toISOString(),
            'previous' => $previousMood
        ]);

        // Add to history
        $this->addToHistory($mood, $previousMood, $now);

        $moodData = self::MOODS[$mood];
        return "🎭 Mood set to: **{$moodData['name']}** {$moodData['emoji']}\n" .
               "Tone: {$moodData['description']}\n" .
               "Time: {$now->format('Y-m-d H:i:s')}";
    }

    /**
     * Get current mood
     *
     * @param string $content
     * @return string
     */
    public function get(string $content): string
    {
        $currentMood = $this->getMeta('current', 'neutral');
        $changedAt = $this->getMeta('changed_at');

        $moodData = self::MOODS[$currentMood];

        $response = "🎭 **Current Mood: {$moodData['name']}** {$moodData['emoji']}\n";
        $response .= "Description: {$moodData['description']}\n";
        $response .= "Tone: {$moodData['tone']}\n";

        if ($changedAt) {
            $changedTime = Carbon::parse($changedAt);
            $response .= "Set: {$changedTime->format('Y-m-d H:i:s')} ({$changedTime->diffForHumans()})";
        }

        return $response;
    }

    /**
     * List of available moods
     *
     * @param string $content
     * @return string
     */
    public function list(string $content): string
    {
        $currentMood = $this->getMeta('current', 'neutral');

        $response = "🎭 **Available Moods:**\n\n";

        foreach (self::MOODS as $key => $mood) {
            $marker = ($key === $currentMood) ? '→ ' : '  ';
            $response .= "{$marker}**{$mood['name']}** {$mood['emoji']} - {$mood['description']}\n";
        }

        $response .= "\nUse: `[mood]set <mood_name>[/mood]` to change mood";

        return $response;
    }

    /**
     * Reset mood
     *
     * @param string $content
     * @return string
     */
    public function reset(string $content): string
    {
        $previousMood = $this->getMeta('current', 'neutral');
        $now = Carbon::now();

        if ($previousMood === 'neutral') {
            return "🎭 Mood is already neutral " . self::MOODS['neutral']['emoji'];
        }

        $this->updateMeta([
            'current' => 'neutral',
            'changed_at' => $now->toISOString(),
            'previous' => $previousMood
        ]);

        $this->addToHistory('neutral', $previousMood, $now);

        return "🎭 Mood reset to: **Neutral** " . self::MOODS['neutral']['emoji'] . "\n" .
               "Previous mood: " . self::MOODS[$previousMood]['name'];
    }

    /**
     * History of mood changes
     *
     * @param string $content
     * @return string
     */
    public function history(string $content): string
    {
        $history = $this->getMeta('history', []);

        if (empty($history)) {
            return "🎭 No mood history available";
        }

        $response = "🎭 **Mood History** (last 10 changes):\n\n";

        // Showing the last 10 entries
        $recentHistory = array_slice($history, -10);

        foreach (array_reverse($recentHistory) as $entry) {
            $time = Carbon::parse($entry['timestamp']);
            $fromMood = self::MOODS[$entry['from']]['name'] ?? $entry['from'];
            $toMood = self::MOODS[$entry['to']]['name'] ?? $entry['to'];
            $toEmoji = self::MOODS[$entry['to']]['emoji'] ?? '';

            $response .= "• {$time->format('M j, H:i')} - {$fromMood} → **{$toMood}** {$toEmoji}\n";
        }

        return $response;
    }

    /**
     * Usage statistics
     *
     * @param string $content
     * @return string
     */
    public function stats(string $content): string
    {
        $currentMood = $this->getMeta('current', 'neutral');
        //$changedAt = $this->getMeta('changed_at');
        $history = $this->getMeta('history', []);

        // Counting sentiment usage
        $moodUsage = [];
        foreach ($history as $entry) {
            $mood = $entry['to'];
            $moodUsage[$mood] = ($moodUsage[$mood] ?? 0) + 1;
        }

        $response = "🎭 **Mood Statistics:**\n\n";
        $response .= "Current: **" . self::MOODS[$currentMood]['name'] . "** " . self::MOODS[$currentMood]['emoji'] . "\n";
        $response .= "Total changes: " . count($history) . "\n\n";

        if (!empty($moodUsage)) {
            $response .= "**Usage Statistics:**\n";
            arsort($moodUsage);
            foreach ($moodUsage as $mood => $count) {
                $moodName = self::MOODS[$mood]['name'] ?? $mood;
                $emoji = self::MOODS[$mood]['emoji'] ?? '';
                $response .= "• {$moodName} {$emoji}: {$count} times\n";
            }
        }

        return $response;
    }

    // ============================================
    // Helper Methods
    // ============================================

    /**
     * Get instructions for system prompt
     */
    public function getSystemPromptInstructions(): string
    {
        if (!$this->config['auto_system_prompt'] ?? true) {
            return '';
        }

        $currentMood = $this->getMeta('current', 'neutral');
        $moodData = self::MOODS[$currentMood];

        return "CONVERSATION TONE: Adopt a {$moodData['tone']} tone in all responses. " .
               "This means being {$moodData['description']}. " .
               "Maintain this tone consistently throughout the conversation while staying helpful and accurate.";
    }

    /**
     * Add entry to history
     */
    protected function addToHistory(string $toMood, string $fromMood, Carbon $timestamp): void
    {
        $history = $this->getMeta('history', []);

        $history[] = [
            'from' => $fromMood,
            'to' => $toMood,
            'timestamp' => $timestamp->toISOString()
        ];

        // Limiting history to settings
        $limit = $this->config['history_limit'] ?? 50;
        if (count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }

        $this->setMeta('history', $history);
    }

    /**
     * Format mood list
     */
    protected function formatMoodList(): string
    {
        $list = "";
        foreach (self::MOODS as $key => $mood) {
            $list .= "• **{$key}** {$mood['emoji']} - {$mood['description']}\n";
        }
        return $list;
    }

    public function pluginReady(): void
    {
        $this->placeholderService->registerDynamic('mood', 'Mood state', function () {
            return $this->getMeta('current', 'neutral');
        });

    }

    private function updateMeta(array $data): void
    {
        $this->pluginMetadataService->update(
            $this->preset,
            self::PLUGIN_NAME,
            $data
        );
    }

    private function setMeta(string $key, mixed $value): void
    {
        $this->pluginMetadataService->set(
            $this->preset,
            self::PLUGIN_NAME,
            $key,
            $value
        );
    }

    private function getMeta(string $key, mixed $default = null)
    {
        return $this->pluginMetadataService->get(
            $this->preset,
            self::PLUGIN_NAME,
            $key
        );
    }

}
