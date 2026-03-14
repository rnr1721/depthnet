<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\EnvironmentInfoServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;

class ShortcodeManagerService implements ShortcodeManagerServiceInterface
{
    public function __construct(
        protected PlaceholderServiceInterface $placeholderService,
        protected CommandInstructionBuilderInterface $commandInstructionBuilder,
        protected EnvironmentInfoServiceInterface $environmentInfoService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function setDefaultShortcodes(): void
    {
        $this->setDateTime();
        $this->setCommandBuilderInstructions();
        $this->setEnvironmentInfo();
        $this->setRagContext();
        $this->setInnerVoice();
        $this->setAgentCommandResults();
        $this->setWorkspace();
    }

    /**
     * Register current date and time shortcode
     *
     * @return void
     */
    private function setDateTime(): void
    {
        $this->placeholderService->registerDynamic('current_datetime', 'Current date and time', function () {
            return date('Y-m-d H:i:s');
        });
    }

    /**
     * Register plugin command instructions shortcode
     *
     * @return void
     */
    private function setCommandBuilderInstructions(): void
    {
        $this->placeholderService->registerDynamic('command_instructions', 'Instructions for plugin commands for model', function () {
            return $this->commandInstructionBuilder->buildInstructions();
        });
    }

    /**
     * Register environment information shortcode
     *
     * @return void
     */
    private function setEnvironmentInfo(): void
    {
        $this->placeholderService->registerDynamic('environment_info', 'Current system environment information', function () {
            return $this->environmentInfoService->getEnvironmentInfo();
        });
    }

    /**
     * Register RAG context placeholder.
     *
     * Registered here so the system knows it exists and shows it in the
     * available placeholders UI. The actual content is injected by
     * CycleContextBuilder / SingleContextBuilder before each request
     * via registerShortcode('rag_context', ...) which overwrites this stub.
     * If the preset has no RAG configured the placeholder resolves to ''.
     *
     * @return void
     */
    private function setRagContext(): void
    {
        $this->placeholderService->registerDynamic(
            'rag_context',
            'Relevant memories retrieved from vector memory before each thinking cycle (requires RAG preset to be configured)',
            fn () => ''
        );
    }

    /**
     * Register inner voice placeholder.
     *
     * Similar to RAG context, this is a stub registered by default and meant to be overwritten by presets that support it.
     * The actual content is injected before each request by the preset's CycleContextBuilder if the preset has inner voice enabled.
     *
     * @return void
     */
    private function setInnerVoice(): void
    {
        $this->placeholderService->registerDynamic(
            'inner_voice',
            'Inner voice: advice, doubt or intuition from a dedicated preset (requires Voice preset to be configured)',
            fn () => ''
        );
    }

    /**
     * Register the [[workspace]] placeholder with an empty stub.
     * The real implementation is provided by WorkspacePlugin::pluginReady()
     * when the plugin is active. This stub ensures the placeholder is always
     * defined even if the plugin is disabled or not registered.
     */
    private function setWorkspace(): void
    {
        $this->placeholderService->registerDynamic(
            'workspace',
            'Persistent key-value scratchpad contents for this preset. Updated each cycle via [workspace] commands.',
            fn () => ''
        );
    }

    /**
     * Register a stub for the "agent_command_results" shortcode.
     *
     * This placeholder acts as a **stub** so that editors and UIs
     * can recognize the shortcode exists, even before the agent runs.
     *
     * The actual value is injected dynamically during the agent's
     * internal execution cycle. Until then, it resolves to an empty string.
     *
     * @return void
     */
    private function setAgentCommandResults(): void
    {
        $this->placeholderService->registerDynamic(
            'agent_command_results',
            'Placeholder for agent command results (filled during internal agent execution)',
            fn () => ''
        );
    }

    /**
     * @inheritDoc
     */
    public function registerShortcode(string $key, string $description, callable $callback): void
    {
        $this->placeholderService->registerDynamic($key, $description, $callback);
    }

    /**
     * @inheritDoc
     */
    public function unregisterShortcode(string $key): bool
    {
        $this->placeholderService->removePlaceholder($key);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredShortcodes(): array
    {
        return $this->placeholderService->getPlaceholders();
    }

    /**
     * @inheritDoc
     */
    public function hasShortcode(string $key): bool
    {
        return $this->placeholderService->hasPlaceholder($key);
    }

    /**
     * @inheritDoc
     */
    public function processShortcodes(string $text): string
    {
        return $this->placeholderService->processContentWithDynamic($text);
    }

    /**
     * @inheritDoc
     */
    public function getShortcodeValue(string $key): ?string
    {
        try {
            return $this->placeholderService->getPlaceholderContent($key);
        } catch (\Exception $e) {
            return null;
        }
    }
}
