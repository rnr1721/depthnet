<?php

namespace App\Services\Agent;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\EnvironmentInfoServiceInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;

class ShortcodeManagerService implements ShortcodeManagerServiceInterface
{
    public function __construct(
        protected PlaceholderServiceInterface $placeholderService,
        protected ShortcodeScopeResolverServiceInterface $scopeResolver,
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
        $this->setKnownSources();
        $this->setPreCommandResults();
        $this->setPersonsContext();
    }

    /**
     * Register current date and time shortcode
     */
    private function setDateTime(): void
    {
        $this->placeholderService->registerDynamic('current_datetime', 'Current date and time', function () {
            return date('Y-m-d H:i:s');
        });
    }

    /**
     * Register plugin command instructions shortcode
     */
    private function setCommandBuilderInstructions(): void
    {
        $this->placeholderService->registerDynamic('command_instructions', 'Instructions for plugin commands for model', function () {
            return $this->commandInstructionBuilder->buildInstructions();
        });
    }

    /**
     * Register environment information shortcode
     */
    private function setEnvironmentInfo(): void
    {
        $this->placeholderService->registerDynamic('environment_info', 'Current system environment information', function () {
            return $this->environmentInfoService->getEnvironmentInfo();
        });
    }

    /**
     * Register RAG context placeholder stub (global).
     * The actual content is injected per-preset by CycleContextBuilder
     * via registerShortcodeForPreset() which places it in the preset scope.
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
     * Register inner voice placeholder stub (global).
     * Actual content is injected per-preset when the preset has inner voice enabled.
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
     * Register the [[workspace]] placeholder stub (global).
     * The real implementation is provided per-preset by WorkspacePlugin::pluginReady().
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
     * Register a stub for the "agent_command_results" shortcode (global).
     * The actual value is injected dynamically during the agent's execution cycle.
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
     * Register known_sources placeholder stub (global).
     * The actual content is injected per-preset by InputPoolService::flush()
     * when known pool items are present.
     *
     * Add this call inside setDefaultShortcodes():
     *   $this->setKnownSources();
     */
    private function setKnownSources(): void
    {
        $this->placeholderService->registerDynamic(
            'known_sources',
            'Data from known sources routed directly into context — sensors, body projections, ambient signals (requires pool input mode with known sources configured)',
            fn () => ''
        );
    }

    /**
     * Register pre_command_results placeholder stub (global).
     * Actual content is injected per-preset by CommandPreRunner
     * before each generation cycle when pre_run_commands are configured.
     */
    private function setPreCommandResults(): void
    {
        $this->placeholderService->registerDynamic(
            'pre_command_results',
            'Results of commands executed before generation (requires pre_run_commands to be configured on the preset)',
            fn () => ''
        );
    }

    private function setPersonsContext(): void
    {
        $this->placeholderService->registerDynamic(
            'persons_context',
            'Relevant person facts from memory, Heart-aware — focuses on people currently in attention (requires Person plugin enabled)',
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
    public function registerShortcodeForPreset(
        int $presetId,
        string $key,
        string $description,
        callable $callback
    ): void {
        $scope = $this->scopeResolver->preset($presetId);
        $this->placeholderService->registerDynamic($key, $description, $callback, $scope);
    }

    /**
     * @inheritDoc
     */
    public function unregisterShortcode(string $key, ?int $presetId = null): bool
    {
        $scope = $presetId !== null
            ? $this->scopeResolver->preset($presetId)
            : $this->scopeResolver->global();

        $this->placeholderService->removePlaceholder($key, $scope);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredShortcodes(?int $presetId = null): array
    {
        return $this->placeholderService->getPlaceholders(
            $this->scopeResolver->buildScopes($presetId)
        );
    }

    /**
     * @inheritDoc
     */
    public function hasShortcode(string $key, ?int $presetId = null): bool
    {
        return $this->placeholderService->hasPlaceholder(
            $key,
            $this->scopeResolver->buildScopes($presetId)
        );
    }

    /**
     * @inheritDoc
     */
    public function processShortcodes(string $text, ?int $presetId = null): string
    {
        return $this->placeholderService->processContentWithDynamic(
            $text,
            $this->scopeResolver->buildScopes($presetId)
        );
    }

    /**
     * @inheritDoc
     */
    public function getShortcodeValue(string $key, ?int $presetId = null): ?string
    {
        try {
            return $this->placeholderService->getPlaceholderContent(
                $key,
                $this->scopeResolver->buildScopes($presetId)
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function clearPresetShortcodes(int $presetId): void
    {
        $this->placeholderService->clear(
            $this->scopeResolver->preset($presetId)
        );
    }
}
