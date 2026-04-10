<?php

namespace Database\Seeders;

use App\Models\AiPreset;
use App\Models\PresetPrompt;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PresetSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (AiPreset::count() > 0) {
            $this->command->info('AI presets already exist. Skipping seeding.');
            return;
        }

        $defaultPresets = config('ai.default_presets', []);

        if (empty($defaultPresets)) {
            $this->command->info('No default presets configured.');
            return;
        }

        foreach ($defaultPresets as $presetData) {
            $systemPrompts = $presetData['system_prompts'] ?? [];
            unset($presetData['system_prompts']);

            $preset = AiPreset::create($presetData);

            $firstPromptId = null;

            foreach ($systemPrompts as $promptData) {
                $prompt = PresetPrompt::create([
                    'preset_id'   => $preset->getId(),
                    'code'        => $promptData['code'],
                    'content'     => $promptData['content'],
                    'description' => $promptData['description'] ?? null,
                ]);

                // First prompt becomes active
                if ($firstPromptId === null) {
                    $firstPromptId = $prompt->getId();
                }
            }

            if ($firstPromptId) {
                $preset->active_prompt_id = $firstPromptId;
                $preset->save();
            }
        }

        $count = count($defaultPresets);
        $this->command->info("Successfully seeded {$count} AI preset(s).");
    }
}
