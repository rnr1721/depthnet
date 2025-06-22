<?php

namespace App\Contracts\Agent;

interface PlaceholderServiceInterface
{
    /**
     * Register a new placeholder
     *
     * @param string $name Placeholder name (without brackets)
     * @param string $description Human-readable description
     * @param string $content Content to replace placeholder with
     * @return self
     */
    public function registerPlaceholder(string $name, string $description, string $content): self;

    /**
     * Register multiple placeholders at once
     *
     * @param array $placeholders Array of ['name' => ['description' => '...', 'content' => '...']]
     * @return self
     */
    public function registerMultiple(array $placeholders): self;

    /**
     * Process content by replacing all registered placeholders
     *
     * @param string $content Content to process
     * @return string Processed content with placeholders replaced
     */
    public function processContent(string $content): string;

    /**
     * Get all registered placeholders for UI
     *
     * @return array Array of ['placeholder_name' => 'description']
     */
    public function getPlaceholders(): array;

    /**
     * Get placeholder content by name
     *
     * @param string $name Placeholder name (with or without brackets)
     * @return string|null Placeholder content or null if not found
     */
    public function getPlaceholderContent(string $name): ?string;

    /**
     * Check if placeholder exists
     *
     * @param string $name Placeholder name (with or without brackets)
     * @return bool
     */
    public function hasPlaceholder(string $name): bool;

    /**
     * Remove a placeholder
     *
     * @param string $name Placeholder name (with or without brackets)
     * @return self
     */
    public function removePlaceholder(string $name): self;

    /**
     * Clear all placeholders
     *
     * @return self
     */
    public function clear(): self;

    /**
     * Get count of registered placeholders
     *
     * @return int
     */
    public function count(): int;

    /**
     * Preview content processing without actually changing anything
     *
     * @param string $content Content to preview
     * @return array ['original' => '...', 'processed' => '...', 'found_placeholders' => [...]]
     */
    public function previewProcessing(string $content): array;

    /**
     * Register placeholder with callable content (lazy evaluation)
     *
     * @param string $name Placeholder name
     * @param string $description Description
     * @param callable $contentProvider Function that returns content when called
     * @return self
     */
    public function registerDynamic(string $name, string $description, callable $contentProvider): self;

    /**
     * Enhanced processing that handles dynamic placeholders
     */
    public function processContentWithDynamic(string $content): string;

}
