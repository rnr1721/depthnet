<?php

namespace Database\Factories;

use App\Models\AiPreset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiPreset>
 */
class AiPresetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AiPreset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true) . ' Preset',
            'description' => $this->faker->sentence(),
            'system_prompt' => $this->faker->paragraph(),
            'engine_name' => $this->faker->randomElement(['openai', 'anthropic', 'mock']),
            'engine_config' => json_encode([
                'temperature' => $this->faker->randomFloat(2, 0, 1),
                'max_tokens' => $this->faker->numberBetween(100, 4000),
            ]),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
            'is_default' => false,
            'notes' => $this->faker->optional()->sentence(),
            'dopamine_level' => $this->faker->numberBetween(1, 10),
            'plugins_disabled' => '',
        ];
    }

    /**
     * Indicate that the preset is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the preset is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a preset for testing purposes.
     */
    public function forTesting(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Test Preset',
            'description' => 'A preset for testing',
            'system_prompt' => 'You are a test AI assistant.',
            'engine_name' => 'mock',
            'engine_config' => json_encode([
                'temperature' => 0.7,
                'max_tokens' => 1000,
            ]),
            'is_active' => true,
            'is_default' => false,
            'notes' => '',
            'dopamine_level' => 5,
            'plugins_disabled' => '',
        ]);
    }

    /**
     * Create a preset with specific engine.
     */
    public function withEngine(string $engine): static
    {
        return $this->state(fn (array $attributes) => [
            'engine_name' => $engine,
        ]);
    }
}
