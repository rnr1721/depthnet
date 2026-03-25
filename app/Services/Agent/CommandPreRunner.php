<?php

namespace App\Services\Agent;

use App\Contracts\Agent\AgentActionsInterface;
use App\Contracts\Agent\CommandPreRunnerInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * CommandPreRunner
 *
 * Executes a preset's pre_run_commands before the main generation cycle
 * and exposes the results via the [[pre_command_results]] shortcode.
 *
 * This solves the two-cycle paradox for inner-voice presets (e.g. a
 * MemoryManager): instead of asking the model to read memory and then act
 * on it in one generation — impossible because command results only arrive
 * in the next cycle — we pre-fetch the data here in PHP before the model
 * is called.
 *
 * Reuses the full AgentActions pipeline (pre-processor, parser, executor,
 * linter) so behaviour is identical to commands the model itself would run.
 *
 * Usage in a preset's system_prompt:
 *   [[pre_command_results]]
 *
 * Configuration (preset field pre_run_commands):
 *   "memory show, workspace list, goal list"
 *   Each entry is "plugin method" or just "plugin" (defaults to "show").
 */
class CommandPreRunner implements CommandPreRunnerInterface
{
    public function __construct(
        protected AgentActionsInterface            $agentActions,
        protected ShortcodeManagerServiceInterface $shortcodeManager,
        protected LoggerInterface                  $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(AiPreset $executionPreset, AiPreset $shortcodePreset, ?AiPreset $mainPreset = null): string
    {
        $raw = $executionPreset->getPreRunCommands();

        if (empty(trim((string) $raw))) {
            $this->registerShortcode($shortcodePreset, '');
            return '';
        }

        $commandText = $this->buildCommandText($raw);

        $this->logger->debug('CommandPreRunner: executing pre-run commands', [
            'preset'       => $executionPreset->getName(),
            'command_text' => $commandText,
        ]);

        try {
            // Reuse the full AgentActions pipeline — pre-processor, parser,
            // executor, linter — exactly as if the model had written these commands.
            $actionsResult = $this->agentActions->runActions(
                $commandText,
                $executionPreset,
                $mainPreset
            );

            $output = $actionsResult->getResult();
        } catch (\Throwable $e) {
            $this->logger->error('CommandPreRunner: execution failed', [
                'preset' => $executionPreset->getName(),
                'error'  => $e->getMessage(),
            ]);
            $output = '';
        }

        $this->registerShortcode($shortcodePreset, $output);
        return $output ?? '';
    }

    /**
     * Convert comma-separated command strings into tagged command text
     * that AgentActions can parse.
     *
     * Examples:
     *   "memory show"   → "[memory show][/memory]"
     *   "workspace"     → "[workspace show][/workspace]"
     *   "goal list"     → "[goal list][/goal]"
     *
     * @param  string $raw
     * @return string
     */
    protected function buildCommandText(string $raw): string
    {
        $lines = [];

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $entry) {
            $parts   = preg_split('/\s+/', $entry, 3);
            $plugin  = strtolower($parts[0] ?? '');
            $method  = strtolower($parts[1] ?? 'show');
            $content = $parts[2] ?? '';

            if (empty($plugin)) {
                continue;
            }

            $lines[] = "[{$plugin} {$method}]{$content}[/{$plugin}]";
        }

        return implode("\n", $lines);
    }

    /**
     * Register (or clear) the [[pre_command_results]] shortcode.
     *
     * @param AiPreset $preset
     * @param string   $output
     */
    protected function registerShortcode(AiPreset $preset, string $output): void
    {
        $captured = $output;

        $this->shortcodeManager->registerShortcodeForPreset(
            $preset->getId(),
            'pre_command_results',
            'Results of pre-run commands executed before generation',
            static fn () => $captured
        );
    }
}
