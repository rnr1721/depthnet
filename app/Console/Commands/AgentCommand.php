<?php

namespace App\Console\Commands;

use App\Contracts\Agent\AgentJobServiceFactoryInterface;
use App\Contracts\Agent\AgentJobServiceInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use Illuminate\Console\Command;

class AgentCommand extends Command
{
    protected $signature = 'agent
        {action : start|stop|status}
        {preset? : Preset ID (required for start/stop; omit for status of all)}
        {--json : Output as JSON (status only)}';

    protected $description = 'Manage per-preset agent thinking loops (start|stop|status)';

    public function handle(
        AgentJobServiceFactoryInterface $agentJobServiceFactory,
        PresetServiceInterface $presetService
    ): int {
        $action = $this->argument('action');

        $service = $agentJobServiceFactory->make();

        return match ($action) {
            'start'  => $this->handleStart($service, $presetService),
            'stop'   => $this->handleStop($service, $presetService),
            'status' => $this->handleStatus($service, $presetService),
            default  => $this->handleInvalidAction(),
        };
    }

    // -------------------------------------------------------------------------

    private function handleStart(
        AgentJobServiceInterface $agentJobService,
        PresetServiceInterface $presetService
    ): int {
        $presetId = $this->resolvePresetId($presetService);
        if ($presetId === null) {
            return 1;
        }

        $this->info("Starting thinking loop for preset {$presetId}...");
        $this->displayPresetStatus($agentJobService, $presetId);

        $success = $agentJobService->updateModelSettings($presetId, true);

        if (!$success) {
            $this->error("Failed to start thinking loop for preset {$presetId}");
            return 1;
        }

        $this->info("Thinking loop started for preset {$presetId}!");
        return 0;
    }

    private function handleStop(
        AgentJobServiceInterface $agentJobService,
        PresetServiceInterface $presetService
    ): int {
        $presetId = $this->resolvePresetId($presetService);
        if ($presetId === null) {
            return 1;
        }

        $this->info("Stopping thinking loop for preset {$presetId}...");
        $settings = $agentJobService->getModelSettings($presetId);
        $this->displayPresetStatus($agentJobService, $presetId);

        if (!$settings['chat_active']) {
            $this->info("Preset {$presetId} is already inactive.");
            return 0;
        }

        $success = $agentJobService->updateModelSettings($presetId, false);

        if (!$success) {
            $this->error("Failed to stop thinking loop for preset {$presetId}");
            return 1;
        }

        $this->info("Thinking loop stopped for preset {$presetId}!");
        return 0;
    }

    private function handleStatus(
        AgentJobServiceInterface $agentJobService,
        PresetServiceInterface $presetService
    ): int {
        $presetIdArg = $this->argument('preset');

        if ($presetIdArg !== null) {
            // Single-preset status
            $presetId = (int) $presetIdArg;
            $settings = $agentJobService->getModelSettings($presetId);

            if ($this->option('json')) {
                $this->line(json_encode($settings, JSON_PRETTY_PRINT));
                return 0;
            }

            $this->info("Agent Status — Preset {$presetId}");
            $this->newLine();
            $this->displayStatusTable([$settings]);
            $this->displayRecommendations($settings);
            return 0;
        }

        // All-presets status
        $allSettings = $agentJobService->getAllModelSettings();

        if ($this->option('json')) {
            $this->line(json_encode($allSettings, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info('Agent Status — All Presets');
        $this->newLine();

        if (empty($allSettings)) {
            $this->warn('No presets are currently active or running.');
            return 0;
        }

        $this->displayStatusTable(array_values($allSettings));
        return 0;
    }

    private function handleInvalidAction(): int
    {
        $this->error('Invalid action. Use: start, stop, or status');
        $this->info('Examples:');
        $this->info('  php artisan agent start 1');
        $this->info('  php artisan agent stop 2');
        $this->info('  php artisan agent status');
        $this->info('  php artisan agent status 1 --json');
        return 1;
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve preset ID from argument or default preset.
     *
     * @param PresetServiceInterface $presetService
     * @return integer|null
     */
    private function resolvePresetId(PresetServiceInterface $presetService): ?int
    {
        $arg = $this->argument('preset');

        if ($arg !== null) {
            return (int) $arg;
        }

        $this->error('Preset ID is required for this action.');
        $this->info('Example: php artisan agent start 1');
        return null;
    }

    private function displayPresetStatus(AgentJobServiceInterface $agentJobService, int $presetId): void
    {
        $s = $agentJobService->getModelSettings($presetId);
        $this->table(
            ['Setting', 'Value'],
            [
                ['Preset ID',   $s['preset_id']],
                ['Active',      $s['chat_active'] ? 'Yes' : 'No'],
                ['Locked',      $s['is_locked'] ? 'Yes (running)' : 'No'],
                ['Can Start',   $s['can_start'] ? 'Yes' : 'No'],
                ['Can Stop',    $s['can_stop'] ? 'Yes' : 'No'],
            ]
        );
    }

    private function displayStatusTable(array $allSettings): void
    {
        $rows = array_map(fn ($s) => [
            $s['preset_id'],
            $s['chat_active'] ? '✓ Active' : '— Inactive',
            $s['is_locked'] ? '✓ Running' : '— Idle',
            $s['can_start'] ? 'Yes' : 'No',
            $s['can_stop'] ? 'Yes' : 'No',
        ], $allSettings);

        $this->table(
            ['Preset ID', 'Status', 'Lock', 'Can Start', 'Can Stop'],
            $rows
        );
    }

    private function displayRecommendations(array $settings): void
    {
        if (!$settings['chat_active']) {
            $this->warn("Preset {$settings['preset_id']} is inactive. Use \"agent start {$settings['preset_id']}\".");
        } elseif ($settings['is_locked']) {
            $this->info("Preset {$settings['preset_id']} is currently thinking...");
        } else {
            $this->info("Preset {$settings['preset_id']} is active and ready.");
        }
    }
}
