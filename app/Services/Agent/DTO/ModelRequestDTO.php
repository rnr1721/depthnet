<?php

namespace App\Services\Agent\DTO;

use App\Contracts\Agent\AiModelRequestInterface;
use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeManagerServiceInterface;
use App\Models\AiPreset;
use Illuminate\Support\Collection;

/**
 * Data Transfer Object for AI model requests
 *
 * Encapsulates all parameters needed for AI model operations
 */
class ModelRequestDTO implements AiModelRequestInterface
{
    private ?Collection $cachedMemoryContent = null;
    private ?string $cachedMemoryFormatted = null;
    private ?string $cachedCommandInstructions = null;

    /**
     * Create a new model request DTO
     *
     * @param AiPreset $preset AI preset with configuration
     * @param MemoryServiceInterface $memoryService Memory service for retrieving memory data
     * @param CommandInstructionBuilderInterface $commandInstructionBuilder Command instruction builder
     * @param ShortcodeManagerServiceInterface $shortcodeManager
     * @param PluginMetadataServiceInterface $pluginMetadataService
     * @param array $context Chat context/history
     * @param array $additionalParams Additional parameters for future extensions
     */
    public function __construct(
        public readonly AiPreset $preset,
        public readonly MemoryServiceInterface $memoryService,
        public readonly CommandInstructionBuilderInterface $commandInstructionBuilder,
        public readonly ShortcodeManagerServiceInterface $shortcodeManager,
        public readonly PluginMetadataServiceInterface $pluginMetadataService,
        public readonly array $context,
        public readonly array $additionalParams = []
    ) {
    }

    /**
     * Get AI preset
     *
     * @return AiPreset
     */
    public function getPreset(): AiPreset
    {
        return $this->preset;
    }

    /**
     * Get memory service
     *
     * @return MemoryServiceInterface
     */
    public function getMemoryService(): MemoryServiceInterface
    {
        return $this->memoryService;
    }

    /**
     * Get command instruction builder
     *
     * @return CommandInstructionBuilderInterface
     */
    public function getCommandInstructionBuilder(): CommandInstructionBuilderInterface
    {
        return $this->commandInstructionBuilder;
    }

    /**
     * Get command instruction builder
     *
     * @return ShortcodeManagerServiceInterface
     */
    public function getShortcodeManager(): ShortcodeManagerServiceInterface
    {
        return $this->shortcodeManager;
    }

    /**
     * Get chat context
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get additional parameters
     *
     * @return array
     */
    public function getAdditionalParams(): array
    {
        return $this->additionalParams;
    }

    /**
     * Get additional parameter by key
     *
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getAdditionalParam(string $key, mixed $default = null): mixed
    {
        return $this->additionalParams[$key] ?? $default;
    }

    /**
     * Get memory content items (cached)
     *
     * @return Collection
     */
    public function getMemoryContent(): Collection
    {
        if ($this->cachedMemoryContent === null) {
            $this->cachedMemoryContent = $this->memoryService->getMemoryItems($this->preset);
        }

        return $this->cachedMemoryContent;
    }

    /**
     * Get formatted memory content (cached)
     *
     * @return string
     */
    public function getMemoryContentFormatted(): string
    {
        if ($this->cachedMemoryFormatted === null) {
            $this->cachedMemoryFormatted = $this->memoryService->getFormattedMemory($this->preset);
        }

        return $this->cachedMemoryFormatted;
    }

    /**
     * Get current dopamine level
     *
     * @return int
     */
    public function getDopamineLevel(): int
    {
        return $this->pluginMetadataService->get($this->preset, 'dopamine', 'current_level', 5);
    }

    /**
     * Get command instructions (cached)
     *
     * @return string
     */
    public function getCommandInstructions(): string
    {
        if ($this->cachedCommandInstructions === null) {
            $this->cachedCommandInstructions = $this->commandInstructionBuilder->buildInstructions();
        }

        return $this->cachedCommandInstructions;
    }

    /**
     * Check if context exists
     *
     * @return bool
     */
    public function hasContext(): bool
    {
        return !empty($this->context);
    }

    /**
     * Check if memory content exists
     *
     * @return bool
     */
    public function hasMemoryContent(): bool
    {
        return $this->getMemoryContent()->isNotEmpty();
    }

    /**
     * Check if formatted memory exists
     *
     * @return bool
     */
    public function hasFormattedMemory(): bool
    {
        return !empty(trim($this->getMemoryContentFormatted()));
    }

    /**
     * Check if command instructions exist
     *
     * @return bool
     */
    public function hasCommandInstructions(): bool
    {
        return !empty(trim($this->getCommandInstructions()));
    }

    /**
     * Get context count
     *
     * @return int
     */
    public function getContextCount(): int
    {
        return count($this->context);
    }

    /**
     * Get memory items count
     *
     * @return int
     */
    public function getMemoryCount(): int
    {
        return $this->getMemoryContent()->count();
    }

    /**
     * Force refresh all cached data
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->cachedMemoryContent = null;
        $this->cachedMemoryFormatted = null;
        $this->cachedCommandInstructions = null;
    }

    /**
     * Convert to array for debugging
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'preset_id' => $this->preset->id,
            'context_count' => $this->getContextCount(),
            'memory_count' => $this->getMemoryCount(),
            'has_command_instructions' => $this->hasCommandInstructions(),
            'dopamine_level' => $this->getDopamineLevel(),
            'additional_params' => $this->additionalParams
        ];
    }
}
