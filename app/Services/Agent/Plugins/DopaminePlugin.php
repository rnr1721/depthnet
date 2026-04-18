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
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'dopamine';
    public const CURRENT_LEVEL = 'current_level';

    public function __construct(
        protected LoggerInterface $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected PluginMetadataServiceInterface $pluginMetadata
    ) {
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
    public function getDescription(array $config = []): string
    {
        $min = $config['min_level'] ?? 0;
        $max = $config['max_level'] ?? 10;
        return "Dopamine level management with reward and penalty operations. Range: {$min}-{$max}.";
    }

    /**
     * @inheritDoc
     */
    public function getInstructions(array $config = []): array
    {
        return [
            "Get dopamine reward for success: [dopamine reward][/dopamine]",
            "Apply dopamine penalty for failure: [dopamine penalty][/dopamine]",
            "Set specific dopamine level: [dopamine set]5[/dopamine]",
            "Show current dopamine level: [dopamine show][/dopamine]"
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
        return "Error: Invalid command format. Use correct syntax";
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
            'default_level' => [
                'type' => 'number',
                'label' => 'Default Level',
                'description' => 'Default dopamine level',
                'min' => 0,
                'max' => 20,
                'value' => 5,
                'required' => true
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
            'enabled' => false,
            'default_level' => 5,
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
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Dopamine plugin is disabled.";
        }

        return "Invalid format. Please use correct syntax";
    }

    /**
     * Apply reward (increase dopamine)
     *
     * @param string $content
     * @param PluginExecutionContext $context
     * @return string
     */
    public function reward(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $currentLevel = $this->getCurrentLevel($context);
            $rewardAmount = $context->get('reward_amount', 1);
            $maxLevel = $context->get('max_level', 10);

            $newLevel = min($maxLevel, $currentLevel + $rewardAmount);
            $this->setCurrentLevel($context, $newLevel);

            $this->logChange($context, 'reward', $currentLevel, $newLevel, $rewardAmount);

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
     * @param PluginExecutionContext $context
     * @return string
     */
    public function penalty(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $currentLevel = $this->getCurrentLevel($context);
            $penaltyAmount = $context->get('penalty_amount', 1);
            $minLevel = $context->get('min_level', 0);

            $newLevel = max($minLevel, $currentLevel - $penaltyAmount);
            $this->setCurrentLevel($context, $newLevel);

            $this->logChange($context, 'penalty', $currentLevel, $newLevel, $penaltyAmount);

            return "Dopamine level decreased from {$currentLevel} to {$newLevel} (-{$penaltyAmount})";
        } catch (\Throwable $e) {
            $this->logger->error("DopaminePlugin::penalty error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    /**
     * Set specific dopamine level
     */
    public function set(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $targetLevel = (int) trim($content);
            $minLevel = $context->get('min_level', 0);
            $maxLevel = $context->get('max_level', 10);

            if ($targetLevel < $minLevel || $targetLevel > $maxLevel) {
                return "Error: Dopamine level must be between {$minLevel} and {$maxLevel}";
            }

            $currentLevel = $this->getCurrentLevel($context);
            $this->setCurrentLevel($context, $targetLevel);

            $this->logChange($context, 'set', $currentLevel, $targetLevel, abs($targetLevel - $currentLevel));

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
     * @param PluginExecutionContext $context
     * @return string
     */
    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Dopamine plugin is disabled.";
        }

        try {
            $currentLevel = $this->getCurrentLevel($context);
            // $minLevel = $this->config['min_level'] ?? 0;
            $maxLevel = $context->get('max_level', 10);

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
     * @param PluginExecutionContext $context
     * @param string $action
     * @param integer $oldLevel
     * @param integer $newLevel
     * @param integer $amount
     * @return void
     */
    private function logChange(PluginExecutionContext $context, string $action, int $oldLevel, int $newLevel, int $amount): void
    {
        if (!($context->get('enable_logging', true))) {
            return;
        }

        $this->logger->info("Dopamine level changed", [
            'preset_id' => $context->preset->getId(),
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

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());
        $this->placeholderService->registerDynamic('dopamine_level', 'Level of model dopamine', function () use ($context) {
            return $this->getCurrentLevel($context);
        }, $scope);
    }

    private function setCurrentLevel(PluginExecutionContext $context, int $newLevel): void
    {
        $this->pluginMetadata->set(
            $context->preset,
            self::PLUGIN_NAME,
            'current_level',
            $newLevel
        );
    }
    private function getCurrentLevel(PluginExecutionContext $context, bool $fresh = false): int
    {
        return $this->pluginMetadata->get(
            $fresh ? $context->preset->fresh() : $context->preset,
            self::PLUGIN_NAME,
            self::CURRENT_LEVEL,
            $context->get('default_level', 5)
        );
    }

    /**
     * @inheritDoc
     */
    public function getSelfClosingTags(): array
    {
        return ['reward', 'penalty', 'show'];
    }
}
