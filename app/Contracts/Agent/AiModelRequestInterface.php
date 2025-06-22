<?php

namespace App\Contracts\Agent;

use App\Contracts\Agent\CommandInstructionBuilderInterface;
use App\Contracts\Agent\Memory\MemoryServiceInterface;
use App\Models\AiPreset;
use Illuminate\Support\Collection;

/**
 * Interface for AI model request data transfer objects
 *
 * Defines contract for passing data to AI model engines
 */
interface AiModelRequestInterface
{
    /**
     * Get AI preset with model configuration
     *
     * @return AiPreset
     */
    public function getPreset(): AiPreset;

    /**
     * Get memory service instance
     *
     * @return MemoryServiceInterface
     */
    public function getMemoryService(): MemoryServiceInterface;

    /**
     * Get command instruction builder instance
     *
     * @return CommandInstructionBuilderInterface
     */
    public function getCommandInstructionBuilder(): CommandInstructionBuilderInterface;

    /**
     * Get command instruction builder
     *
     * @return ShortcodeManagerServiceInterface
     */
    public function getShortcodeManager(): ShortcodeManagerServiceInterface;

    /**
     * Get chat context/history
     *
     * @return array
     */
    public function getContext(): array;

    /**
     * Get additional parameters
     *
     * @return array
     */
    public function getAdditionalParams(): array;

    /**
     * Get additional parameter by key
     *
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getAdditionalParam(string $key, mixed $default = null): mixed;

    /**
     * Get memory content items
     *
     * @return Collection
     */
    public function getMemoryContent(): Collection;

    /**
     * Get formatted memory content
     *
     * @return string
     */
    public function getMemoryContentFormatted(): string;

    /**
     * Get current dopamine level
     *
     * @return int
     */
    public function getDopamineLevel(): int;

    /**
     * Get command instructions
     *
     * @return string
     */
    public function getCommandInstructions(): string;

    /**
     * Check if context exists
     *
     * @return bool
     */
    public function hasContext(): bool;

    /**
     * Check if memory content exists
     *
     * @return bool
     */
    public function hasMemoryContent(): bool;

    /**
     * Check if formatted memory exists
     *
     * @return bool
     */
    public function hasFormattedMemory(): bool;

    /**
     * Check if command instructions exist
     *
     * @return bool
     */
    public function hasCommandInstructions(): bool;

    /**
     * Get context count
     *
     * @return int
     */
    public function getContextCount(): int;

    /**
     * Get memory items count
     *
     * @return int
     */
    public function getMemoryCount(): int;

    /**
     * Force refresh all cached data
     *
     * @return void
     */
    public function refresh(): void;

    /**
     * Convert to array for debugging
     *
     * @return array
     */
    public function toArray(): array;
}
