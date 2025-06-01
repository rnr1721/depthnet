<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use Illuminate\Support\Facades\Log;

class DopaminePlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginPresetTrait;

    public function getName(): string
    {
        return 'dopamine';
    }

    public function getDescription(): string
    {
        return 'Dopamine level management with reward and penalty operations.';
    }

    public function getInstructions(): array
    {
        return [
            'Get the dopamine if your activity is success: [dopamine reward]1[/dopamine]',
            'Remove the dopamine if your activity is not success: [dopamine penalty]1[/dopamine]',
        ];
    }

    public function execute(string $content): string
    {
        // Parse "reward 3" or "penalty 2" format
        if (preg_match('/^(reward|penalty)\s+(\d+)$/i', trim($content), $matches)) {
            $action = strtolower($matches[1]);
            $amount = (int)$matches[2];

            return $action === 'reward'
                ? $this->reward((string)$amount)
                : $this->penalty((string)$amount);
        }

        return "Invalid format. Use 'reward N' or 'penalty N' where N is 1-5.";
    }

    public function reward(string $content): string
    {
        try {
            $amount = (int)$content;
            if ($amount < 1 || $amount > 5) {
                return "Reward amount must be between 1 and 5.";
            }

            $currentLevel = $this->preset->getDopamineLevel();
            $newLevel = min(10, $currentLevel + $amount);

            $this->preset->dopamine_level = $newLevel;
            $this->preset->save();

            return "Dopamine level increased by $amount. New level: $newLevel (was $currentLevel)";
        } catch (\Throwable $e) {
            Log::error("DopaminePlugin::reward error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    public function penalty(string $content): string
    {
        try {
            $amount = (int)$content;
            if ($amount < 1 || $amount > 5) {
                return "Penalty amount must be between 1 and 5.";
            }

            $currentLevel = (int) $this->preset->getDopamineLevel();
            $newLevel = max(0, $currentLevel - $amount);

            $this->preset->dopamine_level = $newLevel;
            $this->preset->save();

            return "Dopamine level decreased by $amount. New level: $newLevel (was $currentLevel)";
        } catch (\Throwable $e) {
            Log::error("DopaminePlugin::penalty error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    public function show(string $content): string
    {
        try {
            $currentLevel = (int) $this->preset->getDopamineLevel();
            $status = match(true) {
                $currentLevel >= 8 => "energetic and confident",
                $currentLevel <= 3 => "demotivated and tired",
                default => "balanced"
            };

            return "Current dopamine level: $currentLevel (feeling $status)";
        } catch (\Throwable $e) {
            Log::error("DophaminePlugin::show error: " . $e->getMessage());
            return "Error reading dopamine level: " . $e->getMessage();
        }
    }
}
