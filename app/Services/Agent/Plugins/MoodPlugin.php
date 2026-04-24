<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Carbon\Carbon;

/**
 * Mood Plugin — control the mood and tone of the AI assistant.
 */
class MoodPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
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
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected PluginMetadataServiceInterface $pluginMetadataService
    ) {
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Manage AI assistant mood and conversation tone. Set emotional context for responses.';
    }

    public function getCustomSuccessMessage(): ?string
    {
        return "Mood command executed successfully. 🎭";
    }

    public function getCustomErrorMessage(): ?string
    {
        return "Error executing mood command. Please check your input.";
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Mood plugin is disabled.";
        }

        // Parse the command from content
        $parts = explode(' ', trim($content), 2);
        $command = $parts[0] ?? 'get';
        $args = $parts[1] ?? '';

        return $this->callMethod($command, $args, $context);
    }

    /**
     * Explicit list — overrides PluginMethodTrait::getAvailableMethods()
     * because we want to advertise only public commands, not internal helpers.
     */
    public function getAvailableMethods(): array
    {
        return ['set', 'get', 'list', 'reset', 'history', 'stats'];
    }

    public function getInstructions(array $config = []): array
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

    public function getToolSchema(array $config = []): array
    {
        $moodKeys = array_keys(self::MOODS);
        $moodList = implode(', ', $moodKeys);
        $defaultMood = $config['default_mood'] ?? 'neutral';

        return [
            'name'        => 'mood',
            'description' => 'Manage conversation mood and tone. '
                . "Available moods: {$moodList}. "
                . 'Current mood is visible via mood placeholder. '
                . "Default: {$defaultMood}.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Operation to perform',
                        'enum'        => ['set', 'get', 'list', 'reset', 'history', 'stats'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => "For set: mood name ({$moodList}). For get, list, reset, history, stats: leave empty.",
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getMergeSeparator(): ?string
    {
        return "\n---\n";
    }

    public function canBeMerged(): bool
    {
        return false; // Mood commands should be executed individually
    }

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

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'default_mood' => 'neutral',
            'auto_system_prompt' => true,
            'history_limit' => 50
        ];
    }

    // ============================================
    // Mood Methods (called via callMethod)
    // ============================================

    public function set(string $content, PluginExecutionContext $context): string
    {
        $mood = strtolower(trim($content));

        if (empty($mood)) {
            return "Please specify a mood. Available moods:\n" . $this->formatMoodList();
        }

        if (!isset(self::MOODS[$mood])) {
            return "Unknown mood: '{$mood}'. Available moods:\n" . $this->formatMoodList();
        }

        $previousMood = $this->getMeta($context, 'current', 'neutral');
        $now = Carbon::now();

        $this->updateMeta($context, [
            'current' => $mood,
            'changed_at' => $now->toISOString(),
            'previous' => $previousMood,
        ]);

        $this->addToHistory($context, $mood, $previousMood, $now);

        $moodData = self::MOODS[$mood];
        return "🎭 Mood set to: **{$moodData['name']}** {$moodData['emoji']}\n" .
               "Tone: {$moodData['description']}\n" .
               "Time: {$now->format('Y-m-d H:i:s')}";
    }

    public function get(string $content, PluginExecutionContext $context): string
    {
        $currentMood = $this->getMeta($context, 'current', 'neutral');
        $changedAt = $this->getMeta($context, 'changed_at');

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

    public function list(string $content, PluginExecutionContext $context): string
    {
        $currentMood = $this->getMeta($context, 'current', 'neutral');

        $response = "🎭 **Available Moods:**\n\n";

        foreach (self::MOODS as $key => $mood) {
            $marker = ($key === $currentMood) ? '→ ' : '  ';
            $response .= "{$marker}**{$mood['name']}** {$mood['emoji']} - {$mood['description']}\n";
        }

        $response .= "\nUse: `[mood]set <mood_name>[/mood]` to change mood";

        return $response;
    }

    public function reset(string $content, PluginExecutionContext $context): string
    {
        $previousMood = $this->getMeta($context, 'current', 'neutral');
        $now = Carbon::now();

        if ($previousMood === 'neutral') {
            return "🎭 Mood is already neutral " . self::MOODS['neutral']['emoji'];
        }

        $this->updateMeta($context, [
            'current' => 'neutral',
            'changed_at' => $now->toISOString(),
            'previous' => $previousMood,
        ]);

        $this->addToHistory($context, 'neutral', $previousMood, $now);

        return "🎭 Mood reset to: **Neutral** " . self::MOODS['neutral']['emoji'] . "\n" .
               "Previous mood: " . self::MOODS[$previousMood]['name'];
    }

    public function history(string $content, PluginExecutionContext $context): string
    {
        $history = $this->getMeta($context, 'history', []);

        if (empty($history)) {
            return "🎭 No mood history available";
        }

        $response = "🎭 **Mood History** (last 10 changes):\n\n";

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

    public function stats(string $content, PluginExecutionContext $context): string
    {
        $currentMood = $this->getMeta($context, 'current', 'neutral');
        $history = $this->getMeta($context, 'history', []);

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

    /**
     * Renamed from pluginReady(). Now receives PluginExecutionContext, and
     * the closure captures it instead of a bare preset.
     */
    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic('mood', 'Mood state', function () use ($context) {
            return $this->getMeta($context, 'current', 'neutral');
        }, $scope);
    }

    public function getSelfClosingTags(): array
    {
        return ['get', 'list', 'reset', 'history'];
    }

    // ============================================
    // Helper Methods
    // ============================================

    /**
     * Helper that needs both the preset AND config (for history_limit).
     * Took $context as input — could have stayed AiPreset + extra arg, but
     * passing $context is cleaner.
     */
    protected function addToHistory(PluginExecutionContext $context, string $toMood, string $fromMood, Carbon $timestamp): void
    {
        $history = $this->getMeta($context, 'history', []);

        $history[] = [
            'from' => $fromMood,
            'to' => $toMood,
            'timestamp' => $timestamp->toISOString(),
        ];

        $limit = $context->get('history_limit', 50);
        if (count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }

        $this->setMeta($context, 'history', $history);
    }

    protected function formatMoodList(): string
    {
        $list = "";
        foreach (self::MOODS as $key => $mood) {
            $list .= "• **{$key}** {$mood['emoji']} - {$mood['description']}\n";
        }
        return $list;
    }

    // Metadata helpers — these only need AiPreset, no config involved.
    // No reason to make them take a context.

    private function updateMeta(PluginExecutionContext $context, array $data): void
    {
        $this->pluginMetadataService->update($context->preset, self::PLUGIN_NAME, $data);
    }

    private function setMeta(PluginExecutionContext $context, string $key, mixed $value): void
    {
        $this->pluginMetadataService->set($context->preset, self::PLUGIN_NAME, $key, $value);
    }

    private function getMeta(PluginExecutionContext $context, string $key, mixed $default = null)
    {
        return $this->pluginMetadataService->get($context->preset, self::PLUGIN_NAME, $key);
    }
}
