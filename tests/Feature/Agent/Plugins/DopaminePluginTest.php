<?php

namespace Tests\Feature\Agent\Plugins;

use Tests\TestCase;
use App\Services\Agent\Plugins\DopaminePlugin;
use App\Models\AiPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;
use Mockery;

class DopaminePluginTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected DopaminePlugin $plugin;
    protected AiPreset $preset;
    protected LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test preset with initial dopamine level
        $this->preset = AiPreset::factory()->forTesting()->create([
            'dopamine_level' => 5 // Start with middle level
        ]);

        // Mock logger with default behavior
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('error')->byDefault();
        $this->logger->shouldReceive('info')->byDefault();

        // Create plugin instance
        $this->plugin = new DopaminePlugin($this->logger);

        // Set the preset using reflection
        $reflection = new \ReflectionClass($this->plugin);
        $presetProperty = $reflection->getProperty('preset');
        $presetProperty->setAccessible(true);
        $presetProperty->setValue($this->plugin, $this->preset);

        // Initialize config
        $reflection = new \ReflectionClass($this->plugin);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->plugin, $this->plugin->getDefaultConfig());
    }

    protected function tearDown(): void
    {
        // Close mockery after parent tearDown to avoid transaction conflicts
        parent::tearDown();
        Mockery::close();
    }

    #[Test]
    public function it_has_correct_basic_properties()
    {
        $this->assertEquals('dopamine', $this->plugin->getName());
        $this->assertStringContainsString('Dopamine level management', $this->plugin->getDescription());
        $this->assertIsArray($this->plugin->getInstructions());
        $this->assertNotEmpty($this->plugin->getInstructions());
    }

    #[Test]
    public function it_has_proper_config_fields()
    {
        $fields = $this->plugin->getConfigFields();

        // Check required fields exist
        $this->assertArrayHasKey('enabled', $fields);
        $this->assertArrayHasKey('min_level', $fields);
        $this->assertArrayHasKey('max_level', $fields);
        $this->assertArrayHasKey('reward_amount', $fields);
        $this->assertArrayHasKey('penalty_amount', $fields);
        $this->assertArrayHasKey('auto_decay', $fields);
        $this->assertArrayHasKey('decay_rate', $fields);
        $this->assertArrayHasKey('enable_logging', $fields);

        // Check field types
        $this->assertEquals('checkbox', $fields['enabled']['type']);
        $this->assertEquals('number', $fields['min_level']['type']);
        $this->assertEquals('number', $fields['max_level']['type']);
        $this->assertEquals('number', $fields['reward_amount']['type']);
        $this->assertEquals('number', $fields['penalty_amount']['type']);
        $this->assertEquals('checkbox', $fields['auto_decay']['type']);
        $this->assertEquals('number', $fields['decay_rate']['type']);
        $this->assertEquals('checkbox', $fields['enable_logging']['type']);
    }

    #[Test]
    public function it_validates_config_correctly()
    {
        // Valid config
        $validConfig = [
            'min_level' => 0,
            'max_level' => 10,
            'reward_amount' => 2,
            'penalty_amount' => 1,
            'decay_rate' => 15
        ];
        $errors = $this->plugin->validateConfig($validConfig);
        $this->assertEmpty($errors);

        // Invalid config - min >= max
        $invalidConfig1 = [
            'min_level' => 5,
            'max_level' => 5
        ];
        $errors = $this->plugin->validateConfig($invalidConfig1);
        $this->assertArrayHasKey('max_level', $errors);

        // Invalid amounts
        $invalidConfig2 = [
            'reward_amount' => 10, // Too high
            'penalty_amount' => 0, // Too low
            'decay_rate' => 100 // Too high
        ];
        $errors = $this->plugin->validateConfig($invalidConfig2);
        $this->assertArrayHasKey('reward_amount', $errors);
        $this->assertArrayHasKey('penalty_amount', $errors);
        $this->assertArrayHasKey('decay_rate', $errors);
    }

    #[Test]
    public function it_applies_reward_successfully()
    {
        // Set initial level to 3
        $this->preset->dopamine_level = 3;
        $this->preset->save();

        $result = $this->plugin->reward('');

        $this->assertStringContainsString('Dopamine level increased from 3 to 4 (+1)', $result);

        $this->preset->refresh();
        $this->assertEquals(4, $this->preset->dopamine_level);
    }

    #[Test]
    public function it_applies_reward_with_custom_amount()
    {
        $this->setConfigValue('reward_amount', 3);
        $this->preset->dopamine_level = 5;
        $this->preset->save();

        $result = $this->plugin->reward('');

        $this->assertStringContainsString('Dopamine level increased from 5 to 8 (+3)', $result);

        $this->preset->refresh();
        $this->assertEquals(8, $this->preset->dopamine_level);
    }

    #[Test]
    public function it_caps_reward_at_max_level()
    {
        $this->setConfigValue('max_level', 10);
        $this->setConfigValue('reward_amount', 3);
        $this->preset->dopamine_level = 9; // Close to max
        $this->preset->save();

        $result = $this->plugin->reward('');

        $this->assertStringContainsString('Dopamine level increased from 9 to 10', $result);

        $this->preset->refresh();
        $this->assertEquals(10, $this->preset->dopamine_level); // Should be capped at max
    }

    #[Test]
    public function it_applies_penalty_successfully()
    {
        // Set initial level to 7
        $this->preset->dopamine_level = 7;
        $this->preset->save();

        $result = $this->plugin->penalty('');

        $this->assertStringContainsString('Dopamine level decreased from 7 to 6 (-1)', $result);

        $this->preset->refresh();
        $this->assertEquals(6, $this->preset->dopamine_level);
    }

    #[Test]
    public function it_applies_penalty_with_custom_amount()
    {
        $this->setConfigValue('penalty_amount', 2);
        $this->preset->dopamine_level = 8;
        $this->preset->save();

        $result = $this->plugin->penalty('');

        $this->assertStringContainsString('Dopamine level decreased from 8 to 6 (-2)', $result);

        $this->preset->refresh();
        $this->assertEquals(6, $this->preset->dopamine_level);
    }

    #[Test]
    public function it_caps_penalty_at_min_level()
    {
        $this->setConfigValue('min_level', 0);
        $this->setConfigValue('penalty_amount', 3);
        $this->preset->dopamine_level = 2; // Close to min
        $this->preset->save();

        $result = $this->plugin->penalty('');

        $this->assertStringContainsString('Dopamine level decreased from 2 to 0', $result);

        $this->preset->refresh();
        $this->assertEquals(0, $this->preset->dopamine_level); // Should be capped at min
    }

    #[Test]
    public function it_sets_specific_level_successfully()
    {
        $this->preset->dopamine_level = 5;
        $this->preset->save();

        $result = $this->plugin->set('8');

        $this->assertStringContainsString('Dopamine level set from 5 to 8', $result);

        $this->preset->refresh();
        $this->assertEquals(8, $this->preset->dopamine_level);
    }

    #[Test]
    public function it_validates_set_level_range()
    {
        $this->setConfigValue('min_level', 0);
        $this->setConfigValue('max_level', 10);

        // Try to set below minimum
        $result = $this->plugin->set('-1');
        $this->assertStringContainsString('Error: Dopamine level must be between 0 and 10', $result);

        // Try to set above maximum
        $result = $this->plugin->set('15');
        $this->assertStringContainsString('Error: Dopamine level must be between 0 and 10', $result);

        // Valid level should work
        $result = $this->plugin->set('7');
        $this->assertStringContainsString('Dopamine level set from', $result);
    }

    #[Test]
    public function it_shows_current_level_with_progress_bar()
    {
        $this->setConfigValue('max_level', 10);
        $this->preset->dopamine_level = 6;
        $this->preset->save();

        $result = $this->plugin->show('');

        $this->assertStringContainsString('Current dopamine level: 6/10 (60%)', $result);
        $this->assertStringContainsString('[██████░░░░]', $result); // Visual progress bar
    }

    #[Test]
    public function it_shows_different_progress_bars()
    {
        $this->setConfigValue('max_level', 5);

        // Test empty bar
        $this->preset->dopamine_level = 0;
        $this->preset->save();
        $result = $this->plugin->show('');
        $this->assertStringContainsString('[░░░░░]', $result);

        // Test full bar
        $this->preset->dopamine_level = 5;
        $this->preset->save();
        $result = $this->plugin->show('');
        $this->assertStringContainsString('[█████]', $result);

        // Test partial bar
        $this->preset->dopamine_level = 3;
        $this->preset->save();
        $result = $this->plugin->show('');
        $this->assertStringContainsString('[███░░]', $result);
    }

    #[Test]
    public function it_logs_changes_when_logging_enabled()
    {
        // Create fresh logger mock for this test
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->byDefault();
        $logger->shouldReceive('info')
            ->once()
            ->with('Dopamine level changed', Mockery::type('array'));

        // Create new plugin instance with the specific logger
        $plugin = new DopaminePlugin($logger);
        $reflection = new \ReflectionClass($plugin);
        $presetProperty = $reflection->getProperty('preset');
        $presetProperty->setAccessible(true);
        $presetProperty->setValue($plugin, $this->preset);

        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $plugin->getDefaultConfig();
        $config['enable_logging'] = true;
        $configProperty->setValue($plugin, $config);

        $plugin->reward('');
    }

    #[Test]
    public function it_does_not_log_when_logging_disabled()
    {
        // Create fresh logger mock for this test
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->byDefault();
        $logger->shouldNotReceive('info');

        // Create new plugin instance with the specific logger
        $plugin = new DopaminePlugin($logger);
        $reflection = new \ReflectionClass($plugin);
        $presetProperty = $reflection->getProperty('preset');
        $presetProperty->setAccessible(true);
        $presetProperty->setValue($plugin, $this->preset);

        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $plugin->getDefaultConfig();
        $config['enable_logging'] = false;
        $configProperty->setValue($plugin, $config);

        $plugin->reward('');
    }

    #[Test]
    public function it_tests_connection_successfully()
    {
        $this->assertTrue($this->plugin->testConnection());
    }

    #[Test]
    public function it_returns_invalid_format_for_execute()
    {
        $result = $this->plugin->execute('invalid');
        $this->assertStringContainsString('Invalid format', $result);
        $this->assertStringContainsString('[dopamine reward]', $result);
        $this->assertStringContainsString('[dopamine penalty]', $result);
    }

    #[Test]
    public function it_has_correct_merge_settings()
    {
        $this->assertNull($this->plugin->getMergeSeparator());
        $this->assertTrue($this->plugin->canBeMerged());
    }

    #[Test]
    public function it_shows_proper_error_message()
    {
        $this->assertStringContainsString(
            'Invalid command format',
            $this->plugin->getCustomErrorMessage()
        );
        $this->assertNull($this->plugin->getCustomSuccessMessage());
    }

    #[Test]
    public function it_handles_different_level_ranges()
    {
        // Test custom range
        $this->setConfigValue('min_level', 2);
        $this->setConfigValue('max_level', 8);

        // Test reward at max
        $this->preset->dopamine_level = 8;
        $this->preset->save();
        $result = $this->plugin->reward('');
        $this->preset->refresh();
        $this->assertEquals(8, $this->preset->dopamine_level); // Should stay at max

        // Test penalty at min
        $this->preset->dopamine_level = 2;
        $this->preset->save();
        $result = $this->plugin->penalty('');
        $this->preset->refresh();
        $this->assertEquals(2, $this->preset->dopamine_level); // Should stay at min
    }

    #[Test]
    public function it_handles_edge_cases_for_set_method()
    {
        // Test setting string that converts to valid number
        $result = $this->plugin->set(' 7 '); // With whitespace
        $this->assertStringContainsString('Dopamine level set', $result);
        $this->preset->refresh();
        $this->assertEquals(7, $this->preset->dopamine_level);

        // Test setting same level
        $currentLevel = $this->preset->dopamine_level;
        $result = $this->plugin->set((string)$currentLevel);
        $this->assertStringContainsString("Dopamine level set from {$currentLevel} to {$currentLevel}", $result);
    }

    #[Test]
    public function it_calculates_percentage_correctly()
    {
        // Test various percentages
        $testCases = [
            ['level' => 0, 'max' => 10, 'expected' => 0],
            ['level' => 5, 'max' => 10, 'expected' => 50],
            ['level' => 10, 'max' => 10, 'expected' => 100],
            ['level' => 3, 'max' => 7, 'expected' => 43], // 3/7 ≈ 42.86% rounded to 43%
        ];

        foreach ($testCases as $case) {
            $this->setConfigValue('max_level', $case['max']);
            $this->preset->dopamine_level = $case['level'];
            $this->preset->save();

            $result = $this->plugin->show('');
            $this->assertStringContainsString("({$case['expected']}%)", $result);
        }
    }

    #[Test]
    public function it_works_with_preset_dopamine_methods()
    {
        // Test that plugin works with AiPreset's dopamine methods
        $this->preset->dopamine_level = 7;
        $this->preset->save();

        $level = $this->preset->getDopamineLevel();
        $this->assertEquals(7, $level);

        // Test reward changes the level
        $this->plugin->reward('');
        $newLevel = $this->preset->fresh()->getDopamineLevel();
        $this->assertEquals(8, $newLevel);
    }

    #[Test]
    public function it_handles_multiple_operations_in_sequence()
    {
        $this->preset->dopamine_level = 5;
        $this->preset->save();

        // Apply reward twice
        $this->plugin->reward('');
        $this->plugin->reward('');
        $this->preset->refresh();
        $this->assertEquals(7, $this->preset->dopamine_level);

        // Apply penalty once
        $this->plugin->penalty('');
        $this->preset->refresh();
        $this->assertEquals(6, $this->preset->dopamine_level);

        // Set to specific level
        $this->plugin->set('3');
        $this->preset->refresh();
        $this->assertEquals(3, $this->preset->dopamine_level);
    }

    // Helper methods

    /**
     * Set config value using reflection
     */
    private function setConfigValue(string $key, $value): void
    {
        $reflection = new \ReflectionClass($this->plugin);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($this->plugin);
        $config[$key] = $value;
        $configProperty->setValue($this->plugin, $config);
    }
}
