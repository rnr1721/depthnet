<?php

namespace Tests\Unit\Services\Agent\Exchange;

use App\Contracts\Agent\Models\EngineRegistryInterface;
use App\Services\Agent\Exchange\PresetExporter;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Security-critical: secrets must never leave the instance in a bundle.
 *
 * These tests target the exporter's secret-stripping in isolation via reflection
 * (the methods are private and pure — no DB needed). They guard the invariant
 * that a future edit can't start emitting real keys.
 *
 * Two mechanisms are covered:
 *   stripSecrets()       — nulls engine fields declared type:password
 *   stripConfigSecrets() — key-name fallback for capability configs
 */
class PresetExporterSecretsTest extends TestCase
{
    private function exporter(array $engineFields = []): PresetExporter
    {
        $engineRegistry = Mockery::mock(EngineRegistryInterface::class);
        $engineRegistry->shouldReceive('getEngineConfigFields')
            ->andReturn($engineFields);

        return new PresetExporter(
            Mockery::mock(\App\Models\AiPreset::class),
            Mockery::mock(\App\Models\Agent::class),
            $engineRegistry,
            Mockery::mock(\Illuminate\Database\DatabaseManager::class),
            Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    private function callPrivate(PresetExporter $exp, string $method, array $args): mixed
    {
        $m = new ReflectionMethod($exp, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($exp, $args);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_password_field_is_nulled_by_declaration(): void
    {
        $exp = $this->exporter([
            'api_key' => ['type' => 'password'],
            'model'   => ['type' => 'text'],
        ]);

        $out = $this->callPrivate($exp, 'stripSecrets', [
            ['api_key' => 'sk-ant-REAL-SECRET', 'model' => 'claude-x', 'temperature' => 0.8],
            'claude',
        ]);

        $this->assertNull($out['api_key'], 'api_key must be nulled');
        $this->assertSame('claude-x', $out['model'], 'non-secret field preserved');
        $this->assertSame(0.8, $out['temperature'], 'non-secret field preserved');
    }

    public function test_non_password_secret_looking_field_is_kept_by_declaration_path(): void
    {
        // If the engine does NOT declare a field as password, stripSecrets leaves
        // it — declaration is authoritative here (capability fallback is separate).
        $exp = $this->exporter([
            'model' => ['type' => 'text'],
        ]);

        $out = $this->callPrivate($exp, 'stripSecrets', [
            ['model' => 'x', 'some_value' => 'kept'],
            'claude',
        ]);

        $this->assertSame('kept', $out['some_value']);
    }

    public function test_capability_fallback_nulls_by_key_name(): void
    {
        $exp = $this->exporter();

        $out = $this->callPrivate($exp, 'stripConfigSecrets', [[
            'api_key'  => 'REAL',
            'token'    => 'REAL',
            'password' => 'REAL',
            'secret'   => 'REAL',
            'model'    => 'keep-me',
            'base_url' => 'https://keep.me',
        ]]);

        $this->assertNull($out['api_key']);
        $this->assertNull($out['token']);
        $this->assertNull($out['password']);
        $this->assertNull($out['secret']);
        $this->assertSame('keep-me', $out['model']);
        $this->assertSame('https://keep.me', $out['base_url']);
    }

    public function test_stripSecrets_falls_back_when_engine_unknown(): void
    {
        // getEngineConfigFields throws (unknown engine) → must fall back to
        // key-name stripping, never emit the secret.
        $engineRegistry = Mockery::mock(EngineRegistryInterface::class);
        $engineRegistry->shouldReceive('getEngineConfigFields')
            ->andThrow(new \RuntimeException('unknown engine'));

        $exp = new PresetExporter(
            Mockery::mock(\App\Models\AiPreset::class),
            Mockery::mock(\App\Models\Agent::class),
            $engineRegistry,
            Mockery::mock(\Illuminate\Database\DatabaseManager::class),
            Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing(),
        );

        $out = $this->callPrivate($exp, 'stripSecrets', [
            ['api_key' => 'REAL-SECRET', 'model' => 'x'],
            'mystery-engine',
        ]);

        $this->assertNull($out['api_key'], 'must not leak a secret even when engine is unknown');
    }
}
