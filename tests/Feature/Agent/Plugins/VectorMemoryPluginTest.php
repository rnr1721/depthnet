<?php

namespace Tests\Feature\Agent\Plugins;

use Tests\TestCase;
use App\Services\Agent\Plugins\VectorMemoryPlugin;
use App\Contracts\Agent\Plugins\TfIdfServiceInterface;
use App\Models\VectorMemory;
use App\Models\AiPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;
use Mockery;

class VectorMemoryPluginTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected VectorMemoryPlugin $plugin;
    protected TfIdfServiceInterface $tfIdfService;
    protected AiPreset $preset;
    protected LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test preset
        $this->preset = AiPreset::factory()->forTesting()->create();

        // Mock logger
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('error')->andReturn(null);

        // Mock TfIdfService
        $this->tfIdfService = Mockery::mock(TfIdfServiceInterface::class);
        $this->tfIdfService->shouldReceive('vectorize')
            ->andReturn(['feature1' => 0.5, 'feature2' => 0.3]); // Mock vector
        $this->tfIdfService->shouldReceive('detectLanguage')
            ->andReturn('en');
        $this->tfIdfService->shouldReceive('tokenize')
            ->andReturn(['test', 'content', 'words']);
        $this->tfIdfService->shouldReceive('setLanguageConfig')
            ->andReturn(null);
        $this->tfIdfService->shouldReceive('findSimilar')
            ->andReturn([
                [
                    'similarity' => 0.85,
                    'memory' => (object)[
                        'content' => 'Test similar content',
                        'created_at' => now()
                    ]
                ]
            ]);

        // Create plugin instance
        $this->plugin = new VectorMemoryPlugin(
            $this->logger,
            $this->tfIdfService,
            new VectorMemory()
        );

        // Set the preset using reflection (since preset property might be protected)
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
        $this->assertEquals('vectormemory', $this->plugin->getName());
        $this->assertStringContainsString('Semantic memory storage', $this->plugin->getDescription());
        $this->assertIsArray($this->plugin->getInstructions());
        $this->assertNotEmpty($this->plugin->getInstructions());
    }

    #[Test]
    public function it_has_proper_config_fields()
    {
        $fields = $this->plugin->getConfigFields();

        // Check required fields exist
        $this->assertArrayHasKey('enabled', $fields);
        $this->assertArrayHasKey('max_entries', $fields);
        $this->assertArrayHasKey('similarity_threshold', $fields);
        $this->assertArrayHasKey('search_limit', $fields);
        $this->assertArrayHasKey('language_mode', $fields);

        // Check field types
        $this->assertEquals('checkbox', $fields['enabled']['type']);
        $this->assertEquals('number', $fields['max_entries']['type']);
        $this->assertEquals('select', $fields['language_mode']['type']);
    }

    #[Test]
    public function it_validates_config_correctly()
    {
        // Valid config
        $validConfig = [
            'max_entries' => 500,
            'similarity_threshold' => 0.5,
            'search_limit' => 10
        ];
        $errors = $this->plugin->validateConfig($validConfig);
        $this->assertEmpty($errors);

        // Invalid config
        $invalidConfig = [
            'max_entries' => 50, // Too low
            'similarity_threshold' => 1.5, // Too high
            'search_limit' => 25 // Too high
        ];
        $errors = $this->plugin->validateConfig($invalidConfig);
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('max_entries', $errors);
        $this->assertArrayHasKey('similarity_threshold', $errors);
        $this->assertArrayHasKey('search_limit', $errors);
    }

    #[Test]
    public function it_stores_content_successfully()
    {
        $content = 'This is a test memory about PHP optimization techniques';

        $result = $this->plugin->store($content);

        $this->assertStringContainsString('Content stored in vector memory successfully', $result);

        // Check database
        $this->assertDatabaseHas('vector_memories', [
            'preset_id' => $this->preset->id,
            'content' => $content
        ]);

        $memory = VectorMemory::where('preset_id', $this->preset->id)->first();
        $this->assertNotNull($memory);
        $this->assertIsArray($memory->tfidf_vector);
        $this->assertIsArray($memory->keywords);
        $this->assertEquals(1.0, $memory->importance);
    }

    #[Test]
    public function it_rejects_empty_content()
    {
        $result = $this->plugin->store('');
        $this->assertStringContainsString('Error: Cannot store empty content', $result);

        $result = $this->plugin->store('   ');
        $this->assertStringContainsString('Error: Cannot store empty content', $result);
    }

    #[Test]
    public function it_searches_memories_successfully()
    {
        // Store some test memories
        $memories = [
            'PHP optimization with database indexes',
            'JavaScript async programming patterns',
            'Laravel Eloquent query optimization',
            'Python data processing techniques'
        ];

        foreach ($memories as $memory) {
            $this->plugin->store($memory);
        }

        // Search for database-related content
        $result = $this->plugin->search('database optimization');

        $this->assertStringContainsString('Found', $result);
        $this->assertStringContainsString('similar memories', $result);
        // Should find PHP and Laravel memories as most relevant
        $this->assertStringContainsString('%', $result); // Similarity percentage
    }

    #[Test]
    public function it_handles_empty_search_query()
    {
        $result = $this->plugin->search('');
        $this->assertStringContainsString('Error: Search query cannot be empty', $result);

        $result = $this->plugin->search('   ');
        $this->assertStringContainsString('Error: Search query cannot be empty', $result);
    }

    #[Test]
    public function it_handles_search_with_no_memories()
    {
        $result = $this->plugin->search('test query');
        $this->assertStringContainsString('No memories found', $result);
        $this->assertStringContainsString('Store some content first', $result);
    }

    #[Test]
    public function it_shows_recent_memories()
    {
        // Store some memories with slight delays to ensure different timestamps
        $memories = [
            'First memory',
            'Second memory',
            'Third memory'
        ];

        foreach ($memories as $memory) {
            $this->plugin->store($memory);
            usleep(100000); // 0.1 second delay
        }

        $result = $this->plugin->recent('2');

        $this->assertStringContainsString('Recent 2 memories', $result);
        // Should show most recent memories first
        $this->assertStringContainsString('Third memory', $result);
        $this->assertStringContainsString('Second memory', $result);
    }

    #[Test]
    public function it_limits_recent_memories_count()
    {
        // Store 5 memories
        for ($i = 1; $i <= 5; $i++) {
            $this->plugin->store("Memory number {$i}");
        }

        // Test various limits
        $result = $this->plugin->recent('25'); // Should clamp to 20
        $this->assertStringContainsString('Recent 5 memories', $result); // Only 5 exist

        $result = $this->plugin->recent('0'); // Should default to minimum 1
        $this->assertStringContainsString('Recent 1 memories', $result);
    }

    #[Test]
    public function it_clears_all_memories()
    {
        // Store some memories
        for ($i = 1; $i <= 3; $i++) {
            $this->plugin->store("Test memory {$i}");
        }

        $this->assertEquals(3, VectorMemory::where('preset_id', $this->preset->id)->count());

        $result = $this->plugin->clear('');

        $this->assertStringContainsString('Cleared 3 vector memories successfully', $result);
        $this->assertEquals(0, VectorMemory::where('preset_id', $this->preset->id)->count());
    }

    #[Test]
    public function it_auto_cleans_old_entries_when_limit_reached()
    {
        // Set low max_entries for testing
        $reflection = new \ReflectionClass($this->plugin);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($this->plugin);
        $config['max_entries'] = 3;
        $config['auto_cleanup'] = true;
        $configProperty->setValue($this->plugin, $config);

        // Store entries up to limit
        for ($i = 1; $i <= 3; $i++) {
            $this->plugin->store("Memory {$i}");
        }

        $this->assertEquals(3, VectorMemory::where('preset_id', $this->preset->id)->count());

        // Store one more - should trigger cleanup
        $this->plugin->store("Memory 4");

        // Should still have only 3 entries, with oldest removed
        $this->assertEquals(3, VectorMemory::where('preset_id', $this->preset->id)->count());

        $memories = VectorMemory::where('preset_id', $this->preset->id)
            ->orderBy('created_at')
            ->pluck('content')
            ->toArray();

        // First memory should be gone, others should remain
        $this->assertNotContains('Memory 1', $memories);
        $this->assertContains('Memory 4', $memories);
    }

    #[Test]
    public function it_handles_different_languages()
    {
        // Test Russian content
        $russianContent = 'Оптимизация базы данных с использованием индексов';
        $result = $this->plugin->store($russianContent);
        $this->assertStringContainsString('successfully', $result);

        // Test English content
        $englishContent = 'Database optimization using proper indexing';
        $result = $this->plugin->store($englishContent);
        $this->assertStringContainsString('successfully', $result);

        // Search should work for both
        $result = $this->plugin->search('database');
        $this->assertStringContainsString('Found', $result);
    }

    #[Test]
    public function it_executes_content_via_execute_method()
    {
        $content = 'Test memory for execute method';
        $result = $this->plugin->execute($content);

        $this->assertStringContainsString('Content stored in vector memory successfully', $result);
        $this->assertDatabaseHas('vector_memories', [
            'preset_id' => $this->preset->id,
            'content' => $content
        ]);
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
        $this->assertFalse($this->plugin->canBeMerged());
    }

    #[Test]
    public function it_truncates_long_content_in_display()
    {
        // Store long content
        $longContent = str_repeat('This is a very long memory content. ', 20);
        $this->plugin->store($longContent);

        $result = $this->plugin->recent('1');

        // Result should contain truncated version with ...
        $this->assertStringContainsString('...', $result);
        // But original content should be stored fully in database
        $memory = VectorMemory::where('preset_id', $this->preset->id)->first();
        // Use trim to ignore whitespace differences
        $this->assertEquals(trim($longContent), trim($memory->content));
        // Also check that content in display is shorter
        $this->assertTrue(strlen($result) < strlen($longContent));
    }

    #[Test]
    public function it_shows_proper_success_and_error_messages()
    {
        $this->assertEquals(
            "Vector memory operation completed successfully.",
            $this->plugin->getCustomSuccessMessage()
        );

        $this->assertEquals(
            "Error: Vector memory operation failed. Check the syntax and try again.",
            $this->plugin->getCustomErrorMessage()
        );
    }
}
