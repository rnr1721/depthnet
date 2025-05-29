<?php

namespace App\Console\Commands;

use App\Contracts\Agent\AgentJobServiceInterface;
use Illuminate\Console\Command;

class AgentCommand extends Command
{
    protected $signature = 'agent {action : start|stop|status} {--json : Output as JSON (status only)}';

    protected $description = 'Manage agent thinking process (start|stop|status)';

    public function handle(AgentJobServiceInterface $agentJobService)
    {
        $action = $this->argument('action');

        return match($action) {
            'start' => $this->handleStart($agentJobService),
            'stop' => $this->handleStop($agentJobService),
            'status' => $this->handleStatus($agentJobService),
            default => $this->handleInvalidAction()
        };
    }

    private function handleStart(AgentJobServiceInterface $agentJobService): int
    {
        $this->info('Starting agent thinking process...');

        $settings = $agentJobService->getModelSettings();
        $this->displayStatusTable($settings);

        $this->info('Force starting agent (ensuring it works)...');

        $success = $agentJobService->updateModelSettings(
            $settings['model_default'],
            true
        );

        if (!$success) {
            $this->error('Failed to start agent thinking process');
            return 1;
        }

        $this->info('Agent thinking process started successfully!');
        $this->info('Monitor logs for thinking cycle activity');
        return 0;
    }

    private function handleStop(AgentJobServiceInterface $agentJobService): int
    {
        $this->info('Stopping agent thinking process...');

        $settings = $agentJobService->getModelSettings();
        $this->displayStatusTable($settings);

        if (!$settings['model_active']) {
            $this->info('Agent is already inactive');
            return 0;
        }

        $this->info('Force stopping agent (complete shutdown)...');

        $success = $agentJobService->updateModelSettings(
            $settings['model_default'],
            false
        );

        if (!$success) {
            $this->error('Failed to stop agent thinking process');
            return 1;
        }

        $this->info('Agent thinking process stopped successfully!');
        return 0;
    }

    private function handleStatus(AgentJobServiceInterface $agentJobService): int
    {
        $settings = $agentJobService->getModelSettings();

        if ($this->option('json')) {
            $this->line(json_encode($settings, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info('Agent Thinking Status');
        $this->newLine();

        $this->displayStatusTable($settings);
        $this->displayActionsTable($settings);
        $this->displayRecommendations($settings);

        return 0;
    }

    private function handleInvalidAction(): int
    {
        $this->error('Invalid action. Use: start, stop, or status');
        $this->info('Examples:');
        $this->info(' php artisan agent start');
        $this->info(' php artisan agent stop');
        $this->info(' php artisan agent status --json');
        return 1;
    }

    private function displayStatusTable(array $settings): void
    {
        $this->table(['Setting', 'Value', 'Status'], [
            ['Model Active', $settings['model_active'] ? 'Yes' : 'No', $settings['model_active'] ? '+' : '-'],
            ['Current Model', $settings['model_default'], ''],
            ['Agent Mode', $settings['model_agent_mode'], $settings['model_agent_mode'] === 'looped' ? 'looped' : 'single'],
            ['Is Locked', $settings['is_locked'] ? 'Yes' : 'No', $settings['is_locked'] ? '+' : '-'],
        ]);
    }

    private function displayActionsTable(array $settings): void
    {
        $this->newLine();
        $this->info('Available Actions:');

        $actions = [
            [$settings['can_start'] ? 'Can Start' : 'Cannot Start',
             $settings['can_start'] ? 'php artisan agent start' : 'Conditions not met'],
            [$settings['can_stop'] ? 'Can Stop' : 'Cannot Stop',
             $settings['can_stop'] ? 'php artisan agent stop' : 'Not running'],
        ];

        $this->table(['Action', 'Command/Reason'], $actions);
    }

    private function displayRecommendations(array $settings): void
    {
        if (!$settings['model_active']) {
            $this->warn('Model is inactive. Use "agent start" to begin thinking cycles.');
        } elseif ($settings['is_locked']) {
            $this->info('Agent is currently thinking...');
        } elseif (!$settings['can_start']) {
            $this->warn('Model is active but cannot start. Check configuration.');
        } else {
            $this->info('Agent is ready and can start thinking cycles.');
        }
    }
}
