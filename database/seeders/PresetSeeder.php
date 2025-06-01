<?php

namespace Database\Seeders;

use App\Models\AiPreset;
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
        // Only seed if no presets exist
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
            AiPreset::create($presetData);
        }

        $count = count($defaultPresets);
        $this->command->info("Successfully seeded {$count} AI preset(s).");
    }
}
