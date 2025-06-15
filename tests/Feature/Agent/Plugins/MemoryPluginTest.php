<?php

namespace Tests\Feature\Agent\Plugins;

use Tests\TestCase;
use App\Services\Agent\Plugins\MemoryPlugin;
use App\Models\AiPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;
use Mockery;

class MemoryPluginTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected MemoryPlugin $plugin;
    protected AiPreset $preset;
    protected LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test preset
        $this->preset = AiPreset::factory()->forTesting()->create([
            'notes' => '' // Start with empty memory
        ]);

        // Mock logger
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('error')->andReturn(null);

        // Create plugin instance
        $this->plugin = new MemoryPlugin($this->logger);

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
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_has_correct_basic_properties()
    {
        $this->assertEquals('memory', $this->plugin->getName());
        $this->assertStringContainsString('Persistent memory storage', $this->plugin->getDescription());
        $this->assertIsArray($this->plugin->getInstructions());
        $this->assertNotEmpty($this->plugin->getInstructions());
    }

    #[Test]
    public function it_has_proper_config_fields()
    {
        $fields = $this->plugin->getConfigFields();

        // Check required fields exist
        $this->assertArrayHasKey('enabled', $fields);
        $this->assertArrayHasKey('memory_limit', $fields);
        $this->assertArrayHasKey('auto_cleanup', $fields);
        $this->assertArrayHasKey('cleanup_strategy', $fields);
        $this->assertArrayHasKey('enable_versioning', $fields);
        $this->assertArrayHasKey('max_versions', $fields);

        // Check field types
        $this->assertEquals('checkbox', $fields['enabled']['type']);
        $this->assertEquals('number', $fields['memory_limit']['type']);
        $this->assertEquals('checkbox', $fields['auto_cleanup']['type']);
        $this->assertEquals('select', $fields['cleanup_strategy']['type']);
        $this->assertEquals('checkbox', $fields['enable_versioning']['type']);
        $this->assertEquals('number', $fields['max_versions']['type']);
    }

    #[Test]
    public function it_validates_config_correctly()
    {
        // Valid config
        $validConfig = [
            'memory_limit' => 5000,
            'max_versions' => 5
        ];
        $errors = $this->plugin->validateConfig($validConfig);
        $this->assertEmpty($errors);

        // Invalid config
        $invalidConfig = [
            'memory_limit' => 50, // Too low
            'max_versions' => 15 // Too high
        ];
        $errors = $this->plugin->validateConfig($invalidConfig);
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('memory_limit', $errors);
        $this->assertArrayHasKey('max_versions', $errors);
    }

    #[Test]
    public function it_appends_content_successfully()
    {
        $content = 'First memory item';
        $result = $this->plugin->append($content);

        $this->assertStringContainsString('Memory item #1 added successfully', $result);

        // Check database
        $this->preset->refresh();
        $this->assertEquals('1. First memory item', $this->preset->notes);
    }

    #[Test]
    public function it_appends_multiple_items_in_numbered_format()
    {
        $items = [
            'First task completed',
            'Second task in progress',
            'Third task planned'
        ];

        foreach ($items as $index => $item) {
            $result = $this->plugin->append($item);
            $expectedNumber = $index + 1;
            $this->assertStringContainsString("Memory item #{$expectedNumber} added successfully", $result);
        }

        $this->preset->refresh();
        $expected = "1. First task completed\n2. Second task in progress\n3. Third task planned";
        $this->assertEquals($expected, $this->preset->notes);
    }

    #[Test]
    public function it_executes_content_via_execute_method()
    {
        $content = 'Test memory via execute';
        $result = $this->plugin->execute($content);

        $this->assertStringContainsString('Memory item #1 added successfully', $result);
        $this->preset->refresh();
        $this->assertEquals('1. Test memory via execute', $this->preset->notes);
    }

    #[Test]
    public function it_replaces_content_successfully()
    {
        // First add some content
        $this->plugin->append('Original content');
        $this->preset->refresh();
        $this->assertEquals('1. Original content', $this->preset->notes);

        // Replace with new content
        $newContent = 'Completely new content';
        $result = $this->plugin->replace($newContent);

        $this->assertStringContainsString('Memory replaced successfully', $result);
        $this->preset->refresh();
        $this->assertEquals($newContent, $this->preset->notes);
    }

    #[Test]
    public function it_deletes_specific_items_by_number()
    {
        // Add multiple items
        $this->plugin->append('First item');
        $this->plugin->append('Second item');
        $this->plugin->append('Third item');

        // Delete middle item
        $result = $this->plugin->delete('2');
        $this->assertStringContainsString('Memory item #2 deleted successfully', $result);

        $this->preset->refresh();
        $expected = "1. First item\n2. Third item"; // Should be renumbered
        $this->assertEquals($expected, $this->preset->notes);
    }

    #[Test]
    public function it_handles_invalid_delete_numbers()
    {
        $this->plugin->append('Only item');

        // Try to delete non-existent item
        $result = $this->plugin->delete('5');
        $this->assertStringContainsString('Error: Item #5 does not exist', $result);

        // Try invalid number
        $result = $this->plugin->delete('0');
        $this->assertStringContainsString('Error: Invalid item number', $result);

        $result = $this->plugin->delete('-1');
        $this->assertStringContainsString('Error: Invalid item number', $result);
    }

    #[Test]
    public function it_deletes_last_item_and_shows_empty_message()
    {
        $this->plugin->append('Only item');

        $result = $this->plugin->delete('1');
        $this->assertStringContainsString('Memory item #1 deleted. Memory is now empty', $result);

        $this->preset->refresh();
        $this->assertEquals('', $this->preset->notes);
    }

    #[Test]
    public function it_clears_all_memory()
    {
        // Add some content
        $this->plugin->append('Item 1');
        $this->plugin->append('Item 2');

        $result = $this->plugin->clear('');
        $this->assertStringContainsString('Memory cleared successfully', $result);

        $this->preset->refresh();
        $this->assertEquals('', $this->preset->notes);
    }

    #[Test]
    public function it_shows_current_memory_content()
    {
        // Empty memory
        $result = $this->plugin->show('');
        $this->assertStringContainsString('Memory is empty', $result);

        // Add some content
        $this->plugin->append('Test item');
        $result = $this->plugin->show('');

        $this->assertStringContainsString('Current memory content', $result);
        $this->assertStringContainsString('1 items', $result);
        $this->assertStringContainsString('1. Test item', $result);
        // Should show character count
        $this->assertStringContainsString('/2000 chars', $result);
    }

    #[Test]
    public function it_handles_memory_limit_correctly()
    {
        // Set very low limit for testing
        $this->setConfigValue('memory_limit', 50);
        $this->setConfigValue('auto_cleanup', false);

        $longContent = str_repeat('This is a very long content. ', 10);
        $result = $this->plugin->append($longContent);

        $this->assertStringContainsString('Error: Memory limit', $result);
        $this->assertStringContainsString('exceeded', $result);
    }

    #[Test]
    public function it_auto_cleans_with_truncate_old_strategy()
    {
        // Set low limit and enable auto cleanup
        $this->setConfigValue('memory_limit', 100);
        $this->setConfigValue('auto_cleanup', true);
        $this->setConfigValue('cleanup_strategy', 'truncate_old');

        // Add items that will exceed limit
        $this->plugin->append('First item that is quite long');
        $this->plugin->append('Second item that is also long');
        $result = $this->plugin->append('Third item that will cause overflow');

        $this->assertStringContainsString('Memory updated with overflow handling', $result);
        $this->assertStringContainsString('truncate_old', $result);

        // Should have removed oldest items
        $this->preset->refresh();
        $this->assertStringNotContainsString('First item', $this->preset->notes);
    }

    #[Test]
    public function it_handles_truncate_new_strategy()
    {
        $this->setConfigValue('memory_limit', 50);
        $this->setConfigValue('auto_cleanup', true);
        $this->setConfigValue('cleanup_strategy', 'truncate_new');

        $this->plugin->append('Short item');
        $result = $this->plugin->append('This is a very long new content that should be truncated');

        $this->assertStringContainsString('Memory updated with overflow handling', $result);
        $this->assertStringContainsString('truncate_new', $result);
    }

    #[Test]
    public function it_handles_reject_strategy()
    {
        $this->setConfigValue('memory_limit', 30);
        $this->setConfigValue('auto_cleanup', true);
        $this->setConfigValue('cleanup_strategy', 'reject');

        $result = $this->plugin->append('This content is definitely too long for the limit');

        $this->assertStringContainsString('Error: Memory limit', $result);
        $this->assertStringContainsString('New content rejected', $result);
    }

    #[Test]
    public function it_handles_compress_strategy()
    {
        $this->setConfigValue('memory_limit', 50);
        $this->setConfigValue('auto_cleanup', true);
        $this->setConfigValue('cleanup_strategy', 'compress');

        $result = $this->plugin->append('Content    with    extra    whitespace    everywhere');

        $this->assertStringContainsString('Memory updated with overflow handling', $result);
        $this->assertStringContainsString('compress', $result);

        // Should compress whitespace
        $this->preset->refresh();
        $this->assertStringNotContainsString('    ', $this->preset->notes);
    }

    #[Test]
    public function it_parses_old_format_memory_correctly()
    {
        // Simulate old format memory
        $this->preset->update(['notes' => 'Old format memory content without numbering']);

        $result = $this->plugin->append('New item');

        $this->preset->refresh();
        $expected = "1. Old format memory content without numbering\n2. New item";
        $this->assertEquals($expected, $this->preset->notes);
    }

    #[Test]
    public function it_parses_numbered_format_correctly()
    {
        // Set up existing numbered content
        $this->preset->update(['notes' => "1. First item\n2. Second item"]);

        $result = $this->plugin->append('Third item');

        $this->preset->refresh();
        $expected = "1. First item\n2. Second item\n3. Third item";
        $this->assertEquals($expected, $this->preset->notes);
    }

    #[Test]
    public function it_tests_connection_successfully()
    {
        $this->assertTrue($this->plugin->testConnection());
    }

    #[Test]
    public function it_has_correct_merge_settings()
    {
        $this->assertEquals("\n", $this->plugin->getMergeSeparator());
        $this->assertTrue($this->plugin->canBeMerged());
    }

    #[Test]
    public function it_shows_proper_success_and_error_messages()
    {
        $this->assertEquals(
            "Memory operation completed successfully.",
            $this->plugin->getCustomSuccessMessage()
        );

        $this->assertEquals(
            "Error: Memory operation failed. Why?",
            $this->plugin->getCustomErrorMessage()
        );
    }

    #[Test]
    public function it_handles_empty_memory_items_correctly()
    {
        // Test with various empty inputs
        $this->preset->update(['notes' => '']);

        $memoryItems = $this->invokePrivateMethod('parseMemoryItems', ['']);
        $this->assertEmpty($memoryItems);

        $memoryItems = $this->invokePrivateMethod('parseMemoryItems', [null]);
        $this->assertEmpty($memoryItems);

        $memoryItems = $this->invokePrivateMethod('parseMemoryItems', ['   ']);
        $this->assertEmpty($memoryItems);
    }

    #[Test]
    public function it_formats_memory_items_correctly()
    {
        $items = ['First item', 'Second item', 'Third item'];
        $formatted = $this->invokePrivateMethod('formatMemoryItems', [$items]);

        $expected = "1. First item\n2. Second item\n3. Third item";
        $this->assertEquals($expected, $formatted);

        // Empty array should return empty string
        $formatted = $this->invokePrivateMethod('formatMemoryItems', [[]]);
        $this->assertEquals('', $formatted);
    }

    #[Test]
    public function it_checks_memory_limit_correctly()
    {
        $this->setConfigValue('memory_limit', 100);

        $shortContent = 'Short content';
        $this->assertTrue($this->invokePrivateMethod('checkMemoryLimit', [$shortContent]));

        $longContent = str_repeat('Very long content ', 20);
        $this->assertFalse($this->invokePrivateMethod('checkMemoryLimit', [$longContent]));
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

    /**
     * Invoke private method using reflection
     */
    private function invokePrivateMethod(string $methodName, array $args = [])
    {
        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->plugin, $args);
    }
}
