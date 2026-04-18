<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\PresetPromptServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Psr\Log\LoggerInterface;

/**
 * PromptPlugin — allows the agent to switch its own active prompt (mode).
 *
 * The agent sees its current mode via the [[current_mode]] placeholder
 * and can switch to any available mode using [mode]code[/mode].
 *
 * Example prompts setup:
 *   default  — balanced, everyday thinking
 *   critic   — skeptical, finds flaws
 *   creative — free-form, associative
 *   focus    — terse, task-only
 */
class PromptPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public function __construct(
        protected PresetPromptServiceInterface $promptService,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface $placeholderService,
        protected LoggerInterface $logger
    ) {
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return 'mode';
    }

    public function getDescription(array $config = []): string
    {
        return 'Switch thinking mode (active prompt). Use to change personality, focus, or reasoning style mid-session.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'Switch to a different thinking mode: [mode]code[/mode]',
            'List available modes: [mode list][/mode]',
            'Show current mode: [mode current][/mode]',
        ];
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Mode Switching',
                'description' => 'Allow agent to switch its active prompt during a session',
                'required'    => false,
            ],
            'log_switches' => [
                'type'        => 'checkbox',
                'label'       => 'Log Mode Switches',
                'description' => 'Write a log entry each time the agent switches mode',
                'value'       => true,
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'      => false,
            'log_switches' => true,
        ];
    }

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * Default execute — switch to the given mode code.
     * [mode]critic[/mode]
     */
    public function execute(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Mode switching is disabled.";
        }

        $code = trim($content);

        if (empty($code)) {
            return "Error: Mode code is required. Use mode command with code. Available: "
                . implode(', ', $context->preset->getAvailablePromptCodes());
        }

        // Already in this mode?
        $current = $this->currentCode($context);
        if ($current === $code) {
            return "Already in '{$code}' mode.";
        }

        try {
            $prompt = $this->promptService->setActiveByCode($context->preset, $code);

            if ($context->get('log_switches', true)) {
                $this->logger->info('PromptPlugin: mode switched', [
                    'preset_id' => $context->preset->id,
                    'from'      => $current,
                    'to'        => $code,
                ]);
            }

            $desc = $prompt->getDescription() ? " ({$prompt->getDescription()})" : '';
            return "Mode switched to '{$code}'{$desc}. New prompt takes effect from the next cycle.";

        } catch (\RuntimeException $e) {
            return "Error: " . $e->getMessage();
        }
    }

    /**
     * List all available modes for this preset.
     * [mode list][/mode]
     */
    public function list(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Mode switching is disabled.";
        }

        $prompts = $this->promptService->getAll($context->preset);

        if ($prompts->isEmpty()) {
            return "No modes available for this preset.";
        }

        $currentCode = $this->currentCode($context);
        $lines = ["Available modes:"];

        foreach ($prompts as $prompt) {
            $active = $prompt->getCode() === $currentCode ? ' ← current' : '';
            $desc   = $prompt->getDescription() ? " — {$prompt->getDescription()}" : '';
            $lines[] = "  • {$prompt->getCode()}{$desc}{$active}";
        }

        return implode("\n", $lines);
    }

    /**
     * Show the current mode code.
     * [mode current][/mode]
     */
    public function current(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return "Error: Mode switching is disabled.";
        }

        $code = $this->currentCode($context);
        $prompt = $this->promptService->findByCode($context->preset, $code);
        $desc = $prompt?->getDescription() ? " — {$prompt->getDescription()}" : '';

        return "Current mode: '{$code}'{$desc}";
    }

    // ── Placeholder registration ──────────────────────────────────────────────

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        // [[current_mode]] — code of the currently active prompt
        $this->placeholderService->registerDynamic(
            'current_mode',
            'Current thinking mode (active prompt code)',
            function () use ($context) {
                return $this->currentCode($context);
            },
            $scope
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get the code of the currently active prompt.
     * Falls back to 'default' if nothing is set.
     */
    private function currentCode(PluginExecutionContext $context): string
    {
        $active = $this->promptService->getActive($context->preset);
        return $active?->getCode() ?? 'default';
    }

    // ── Interface stubs ───────────────────────────────────────────────────────

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['list', 'current'];
    }
}
