<?php

namespace Database\Seeders;

use App\Models\Option;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OptionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $options = [
            // Agent Settings
            [
                'key' => 'agent_show_commands',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Enable user registration',
                'is_system' => true,
            ],
            [
                'key' => 'agent_show_results',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Enable user registration',
                'is_system' => true,
            ],
            [
                'key' => 'agent_command_parser_mode',
                'value' => 'smart',
                'type' => 'string',
                'description' => 'Default agent command parser',
                'is_system' => true,
            ],
            [
                'key' => 'model_default',
                'value' => 'mock',
                'type' => 'string',
                'description' => 'Default AI model for the agent',
                'is_system' => true,
            ],
            [
                'key' => 'plugin_notepad_content',
                'value' => 'Here is your notepad content. You can add notes here.',
                'type' => 'string',
                'description' => 'Notepad content for AI agent',
                'is_system' => true,
            ],
            [
                'key' => 'model_message_from_user',
                'value' => 'User says:',
                'type' => 'string',
                'description' => 'Message from user text',
                'is_system' => true,
            ],
            [
                'key' => 'model_message_thinking_phrase',
                'value' => 'Thinking: ',
                'type' => 'string',
                'description' => 'Thinking phrase for AI agent',
                'is_system' => true,
            ],

            // Chat Settings
            [
                'key' => 'chat_max_chat_history',
                'value' => '100',
                'type' => 'integer',
                'description' => 'Maximum chat history entries',
                'is_system' => true,
            ],

            // Site Settings
            [
                'key' => 'site_locale',
                'value' => 'en',
                'type' => 'string',
                'description' => 'Site default locale/language',
                'is_system' => true,
            ],
            [
                'key' => 'site_enable_registration',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Enable user registration',
                'is_system' => true,
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($options as $option) {
            if (!Option::where('key', $option['key'])->exists()) {
                Option::create($option);
                $created++;
                $this->command->info("Created option: {$option['key']}");
            } else {
                $skipped++;
                $this->command->info("Skipped existing option: {$option['key']}");
            }
        }

        $this->command->info("🎉 Options seeded successfully! Created: {$created}, Skipped: {$skipped}");

    }
}
