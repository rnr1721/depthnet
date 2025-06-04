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
            'Get the dopamine if your activity is success: [dopamine reward][/dopamine]',
            'Remove the dopamine if your activity is not success: [dopamine penalty][/dopamine]',
        ];
    }

    public function execute(string $content): string
    {
        return "Invalid format. Use '[dopamine reward][/dopamine]' or '[dopamine penalty][/dopamine]'";
    }

    public function reward(string $content): string
    {
        try {
            $currentLevel = $this->preset->getDopamineLevel();
            $newLevel = min(10, $currentLevel + 1); // всегда +1
            $this->preset->dopamine_level = $newLevel;
            $this->preset->save();
            return "Dopamine level increased to $newLevel";
        } catch (\Throwable $e) {
            Log::error("DopaminePlugin::reward error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    public function penalty(string $content): string
    {
        try {
            $currentLevel = (int) $this->preset->getDopamineLevel();
            $newLevel = max(0, $currentLevel - 1); // всегда -1
            $this->preset->dopamine_level = $newLevel;
            $this->preset->save();
            return "Dopamine level decreased to $newLevel";
        } catch (\Throwable $e) {
            Log::error("DopaminePlugin::penalty error: " . $e->getMessage());
            return "Error adjusting dopamine: " . $e->getMessage();
        }
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

}
