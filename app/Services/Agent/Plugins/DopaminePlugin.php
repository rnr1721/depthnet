<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use Psr\Log\LoggerInterface;

/**
 * DopaminePlugin class
 *
 * This plugin manages dopamine levels with reward and penalty operations.
 * It allows setting, showing, rewarding, and penalizing dopamine levels.
 */
class DopaminePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;
    use PluginConfigTrait;

    public function __construct(protected LoggerInterface $logger)
    {
        $this->initializeConfig();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'dopamine';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $min = $this->config['min_level'] ?? 0;
        $max = $this->config['max_level'] ?? 10;
        return "Dopamine level management with reward and penalty operations. Range: {$min}-{$max}.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(): array
    {
        return [
            'Get dopamine reward for success: [dopamine reward][/dopamine]',
            'Apply dopamine penalty for failure: [dopamine penalty][/dopamine]',
            'Set specific dopamine level: [dopamine set]5[/dopamine]',
            'Show current dopamine level: [dopamine show][/dopamine]'
        ];
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
        return "Error: Invalid command format. Use '[dopamine reward][/dopamine]', '[dopamine penalty][/dopamine]'";
    }

    /**
     * @inheritDoc
     */
    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => 'Enable Dopamine Plugin',
                'description' => 'Allow dopamine level management',
                'required' => false
            ],
            'min_level' => [
                'type' => 'number',
                'label' => 'Minimum Level',
                'description' => 'Minimum dopamine level',
                'min' => 0,
                'max' => 10,
                'value' => 0,
                'required' => false
            ],
            'max_level' => [
                'type' => 'number',
                'label' => 'Maximum Level',
                'description' => 'Maximum dopamine level',
                'min' => 5,
                'max' => 20,
                'value' => 10,
                'required' => false
            ],
            'reward_amount' => [
                'type' => 'number',
                'label' => 'Reward Amount',
                'description' => 'Points added for successful actions',
                'min' => 1,
                'max' => 5,
                'value' => 1,
                'required' => false
            ],
            'penalty_amount' => [
                'type' => 'number',
                'label' => 'Penalty Amount',
                'description' => 'Points removed for failed actions',
                'min' => 1,
                'max' => 5,
                'value' => 1,
                'required' => false
            ],
            'auto_decay' => [
                'type' => 'checkbox',
                'label' => 'Auto Decay',
                'description' => 'Automatically reduce dopamine over time',
                'value' => false,
                'required' => false
            ],
            'decay_rate' => [
                'type' => 'number',
                'label' => 'Decay Rate (minutes)',
                'description' => 'Minutes between automatic decay events',
                'min' => 1,
                'max' => 60,
                'value' => 10,
                'required' => false
            ],
            'enable_logging' => [
                'type' => 'checkbox',
                'label' => 'Enable Logging',
                'description' => 'Log dopamine level changes',
                'value' => true,
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

        // Validate level range
        $minLevel = $config['min_level'] ?? 0;
        $maxLevel = $config['max_level'] ?? 10;

        if ($minLevel >= $maxLevel) {
            $errors['max_level'] = 'Maximum level must be greater than minimum level';
        }

        // Validate amounts
        if (isset($config['reward_amount'])) {
            $reward = (int) $config['reward_amount'];
            if ($reward < 1 || $reward > 5) {
                $errors['reward_amount'] = 'Reward amount must be between 1 and 5';
            }
        }

        if (isset($config['penalty_amount'])) {
            $penalty = (int) $config['penalty_amount'];
            if ($penalty < 1 || $penalty > 5) {
                $errors['penalty_amount'] = 'Penalty amount must be between 1 and 5';
            }
        }

        // Validate decay rate
        if (isset($config['decay_rate'])) {
            $decay = (int) $config['decay_rate'];
            if ($decay < 1 || $decay > 60) {
                $errors['decay_rate'] = 'Decay rate must be between 1 and 60 minutes';
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
            'min_level' => 0,
            'max_level' => 10,
            'reward_amount' => 1,
            'penalty_amount' => 1,
            'auto_decay' => false,
            'decay_rate' => 10,
            'enable_logging' => true
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
            // Test basic dopamine operations
            $originalLevel = $this->preset->getDopamineLevel();

            // Test setting level
            $this->preset->dopamine_level = 5;
            $this->preset->save();

            // Test reading level
            $testLevel = $this->preset->fresh()->getDopamineLevel();

            // Restore original level
            $this->preset->dopamine_level = $originalLevel;
            $this->preset->save();

            return $testLevel === 5;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Dopamine plugin is disabled.";
        }

        return "Invalid format. Use '[dopamine reward][/dopamine]', '[dopamine penalty][/dopamine]', '[dopamine set]X[/dopamine]', or '[dopamine show][/dopamine]'";
    }

    /**
     * Apply reward (increase dopamine)
     *
     * @param string $content
     * @return string
     */
    public function reward(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $currentLevel = $this->preset->getDopamineLevel();
            $rewardAmount = $this->config['reward_amount'] ?? 1;
            $maxLevel = $this->config['max_level'] ?? 10;

            $newLevel = min($maxLevel, $currentLevel + $rewardAmount);
            $this->preset->dopamine_level = $newLevel;
            $this->preset->save();

            $this->logChange('reward', $currentLevel, $newLevel, $rewardAmount);

            return "Dopamine level increased from {$currentLevel} to {$newLevel} (+{$rewardAmount})";
        } catch (\Throwable $e) {
            $this->logger->error("DopaminePlugin::reward error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    /**
     * Apply penalty (decrease dopamine)
     *
     * @param string $content
     * @return string
     */
    public function penalty(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $currentLevel = $this->preset->getDopamineLevel();
            $penaltyAmount = $this->config['penalty_amount'] ?? 1;
            $minLevel = $this->config['min_level'] ?? 0;

            $newLevel = max($minLevel, $currentLevel - $penaltyAmount);
            $this->preset->dopamine_level = $newLevel;
            $this->preset->save();

            $this->logChange('penalty', $currentLevel, $newLevel, $penaltyAmount);

            return "Dopamine level decreased from {$currentLevel} to {$newLevel} (-{$penaltyAmount})";
        } catch (\Throwable $e) {
            $this->logger->error("DopaminePlugin::penalty error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    /**
     * Set specific dopamine level
     */
    public function set(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $targetLevel = (int) trim($content);
            $minLevel = $this->config['min_level'] ?? 0;
            $maxLevel = $this->config['max_level'] ?? 10;

            if ($targetLevel < $minLevel || $targetLevel > $maxLevel) {
                return "Error: Dopamine level must be between {$minLevel} and {$maxLevel}";
            }

            $currentLevel = $this->preset->getDopamineLevel();
            $this->preset->dopamine_level = $targetLevel;
            $this->preset->save();

            $this->logChange('set', $currentLevel, $targetLevel, abs($targetLevel - $currentLevel));

            return "Dopamine level set from {$currentLevel} to {$targetLevel}";
        } catch (\Throwable $e) {
            $this->logger->error("DopaminePlugin::set error: " . $e->getMessage());
            return "Error setting dopamine: " . $e->getMessage();
        }
    }

    /**
     * Show current dopamine level
     *
     * @param string $content
     * @return string
     */
    public function show(string $content): string
    {
        if (!$this->isEnabled()) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $currentLevel = $this->preset->getDopamineLevel();
            $minLevel = $this->config['min_level'] ?? 0;
            $maxLevel = $this->config['max_level'] ?? 10;

            $percentage = round(($currentLevel / $maxLevel) * 100);
            $bar = str_repeat('█', $currentLevel) . str_repeat('░', $maxLevel - $currentLevel);

            return "Current dopamine level: {$currentLevel}/{$maxLevel} ({$percentage}%)\n[{$bar}]";
        } catch (\Throwable $e) {
            $this->logger->error("DopaminePlugin::show error: " . $e->getMessage());
            return "Error reading dopamine level: " . $e->getMessage();
        }
    }

    /**
     * Log dopamine level changes
     *
     * @param string $action
     * @param integer $oldLevel
     * @param integer $newLevel
     * @param integer $amount
     * @return void
     */
    private function logChange(string $action, int $oldLevel, int $newLevel, int $amount): void
    {
        if (!($this->config['enable_logging'] ?? true)) {
            return;
        }

        $this->logger->info("Dopamine level changed", [
            'preset_id' => $this->preset->id,
            'action' => $action,
            'old_level' => $oldLevel,
            'new_level' => $newLevel,
            'amount' => $amount,
            'timestamp' => now()
        ]);
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
        return true;
    }

}
