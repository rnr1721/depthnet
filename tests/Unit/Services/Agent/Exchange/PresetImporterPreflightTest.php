<?php

namespace Tests\Unit\Services\Agent\Exchange;

use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Contracts\Agent\Models\PresetRegistryInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Contracts\Agent\Orchestrator\AgentServiceInterface;
use App\Contracts\Agent\PluginManagerInterface;
use App\Contracts\Agent\Prompt\PresetPromptServiceInterface;
use App\Services\Agent\Exchange\PresetImporter;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PresetImporter::preflight — the most branch-heavy piece of the
 * exchange feature, and the one most likely to regress.
 *
 * No database. All collaborators are mocked; the DB-touching checks
 * (code collisions) are stubbed to "nothing taken" via a query-builder mock, so
 * these tests exercise pure preflight logic on in-memory bundles.
 *
 * What's covered here:
 *   - envelope validation (format, format_version)
 *   - empty bundle
 *   - dangling *_ref detection
 *   - active-prompt cardinality
 *   - engine availability (error) vs capability driver absence (warning)
 *   - accumulation (all problems collected, not fail-fast)
 */
class PresetImporterPreflightTest extends TestCase
{
    private function makeImporter(
        array $knownEngines = ['claude', 'novita', 'deepseek'],
        array $knownDrivers = ['novita', 'browser', 'openai_compatible'],
        array $takenPresetCodes = [],
        array $takenAgentCodes = [],
    ): PresetImporter {
        // Engine registry: has() answers from $knownEngines.
        $engineRegistry = Mockery::mock(EngineRegistryInterface::class);
        $engineRegistry->shouldReceive('has')->andReturnUsing(
            fn ($name) => in_array($name, $knownEngines, true)
        );

        // Capability registries: each is a CONCRETE class in the constructor
        // signature (the four are indistinguishable by interface, so they're
        // injected as concrete types). Mockery mocks the concrete class without
        // calling its real constructor. has() answers from $knownDrivers.
        $embeddingRegistry = Mockery::mock(\App\Services\Agent\Capabilities\Embedding\EmbeddingRegistry::class);
        $visionRegistry    = Mockery::mock(\App\Services\Agent\Capabilities\Vision\VisionRegistry::class);
        $sttRegistry       = Mockery::mock(\App\Services\Agent\Capabilities\Speech\SttRegistry::class);
        $ttsRegistry       = Mockery::mock(\App\Services\Agent\Capabilities\Speech\TtsRegistry::class);

        foreach ([$embeddingRegistry, $visionRegistry, $sttRegistry, $ttsRegistry] as $reg) {
            $reg->shouldReceive('has')->andReturnUsing(
                fn ($d) => in_array($d, $knownDrivers, true)
            );
        }

        // AiPreset model mock: whereIn('preset_code', ...)->pluck() → taken codes.
        $presetModel = Mockery::mock(\App\Models\AiPreset::class);
        $presetModel->shouldReceive('whereIn')->with('preset_code', Mockery::any())
            ->andReturnSelf();
        $presetModel->shouldReceive('pluck')->with('preset_code')
            ->andReturn(collect($takenPresetCodes));

        // DatabaseManager mock for agent code check: table('agents')->whereIn->pluck.
        $qb = Mockery::mock();
        $qb->shouldReceive('whereIn')->andReturnSelf();
        $qb->shouldReceive('pluck')->andReturn(collect($takenAgentCodes));
        $db = Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $db->shouldReceive('table')->with('agents')->andReturn($qb);

        // Logger ignores all calls — we don't assert on logging here.
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();

        return new PresetImporter(
            Mockery::mock(PresetServiceInterface::class),
            Mockery::mock(PresetPromptServiceInterface::class),
            Mockery::mock(PluginManagerInterface::class),
            Mockery::mock(PresetRegistryInterface::class),
            $engineRegistry,
            Mockery::mock(AgentServiceInterface::class),
            $presetModel,
            $embeddingRegistry,
            $visionRegistry,
            $sttRegistry,
            $ttsRegistry,
            $db,
            $logger,
        );
    }

    /** A minimal valid single-preset bundle. */
    private function validBundle(array $overrides = []): array
    {
        $preset = array_merge([
            'ref'          => 'ref-1',
            'name'         => 'Test Preset',
            'engine_name'  => 'claude',
            'preset_code'  => null,
            'prompts'      => [['code' => 'default', 'content' => 'x', 'is_active' => true]],
        ], $overrides);

        return [
            'format'         => 'depthnet.bundle',
            'format_version' => 1,
            'presets'        => [$preset],
            'agents'         => [],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_valid_bundle_passes(): void
    {
        $r = $this->makeImporter()->preflight($this->validBundle());

        $this->assertFalse($r->hasErrors(), implode('; ', $r->errors));
        $this->assertEmpty($r->warnings);
        $this->assertSame(1, $r->presetCount);
    }

    public function test_wrong_format_is_rejected(): void
    {
        $bundle = $this->validBundle();
        $bundle['format'] = 'something.else';

        $r = $this->makeImporter()->preflight($bundle);

        $this->assertTrue($r->hasErrors());
    }

    public function test_future_format_version_is_rejected(): void
    {
        $bundle = $this->validBundle();
        $bundle['format_version'] = 999;

        $r = $this->makeImporter()->preflight($bundle);

        $this->assertTrue($r->hasErrors());
    }

    public function test_empty_bundle_is_rejected(): void
    {
        $r = $this->makeImporter()->preflight([
            'format' => 'depthnet.bundle', 'format_version' => 1,
            'presets' => [], 'agents' => [],
        ]);

        $this->assertTrue($r->hasErrors());
    }

    public function test_unavailable_engine_is_error(): void
    {
        $r = $this->makeImporter(knownEngines: ['claude'])
            ->preflight($this->validBundle(['engine_name' => 'nonexistent']));

        $this->assertTrue($r->hasErrors());
        $this->assertStringContainsString('nonexistent', implode(' ', $r->errors));
    }

    public function test_missing_active_prompt_is_error(): void
    {
        $r = $this->makeImporter()->preflight($this->validBundle([
            'prompts' => [['code' => 'default', 'content' => 'x', 'is_active' => false]],
        ]));

        $this->assertTrue($r->hasErrors());
    }

    public function test_two_active_prompts_is_error(): void
    {
        $r = $this->makeImporter()->preflight($this->validBundle([
            'prompts' => [
                ['code' => 'a', 'content' => 'x', 'is_active' => true],
                ['code' => 'b', 'content' => 'y', 'is_active' => true],
            ],
        ]));

        $this->assertTrue($r->hasErrors());
    }

    public function test_dangling_rag_ref_is_error(): void
    {
        $r = $this->makeImporter()->preflight($this->validBundle([
            'rag_configs' => [['rag_preset_ref' => 'ref-does-not-exist', 'sort_order' => 0]],
        ]));

        $this->assertTrue($r->hasErrors());
        $this->assertStringContainsString('not present in the bundle', implode(' ', $r->errors));
    }

    public function test_missing_capability_driver_is_warning_not_error(): void
    {
        $r = $this->makeImporter(knownDrivers: ['browser']) // novita absent
            ->preflight($this->validBundle([
                'capability_configs' => [
                    ['capability' => 'embedding', 'driver' => 'novita', 'config' => ['api_key' => null]],
                ],
            ]));

        // Driver absence must NOT block the import…
        $this->assertFalse($r->hasErrors(), implode('; ', $r->errors));
        // …but must surface as a warning.
        $this->assertNotEmpty($r->warnings);
        $this->assertStringContainsString('novita', implode(' ', $r->warnings));
    }

    public function test_code_collision_is_error(): void
    {
        $r = $this->makeImporter(takenPresetCodes: ['taken_code'])
            ->preflight($this->validBundle(['preset_code' => 'taken_code']));

        $this->assertTrue($r->hasErrors());
        $this->assertStringContainsString('already exists', implode(' ', $r->errors));
    }

    public function test_problems_accumulate_not_fail_fast(): void
    {
        // Two independent problems in one bundle → both reported.
        $r = $this->makeImporter(knownEngines: ['claude'])->preflight($this->validBundle([
            'engine_name' => 'nonexistent',                                       // error 1
            'prompts'     => [['code' => 'd', 'content' => 'x', 'is_active' => false]], // error 2
        ]));

        $this->assertGreaterThanOrEqual(2, count($r->errors));
    }
}
