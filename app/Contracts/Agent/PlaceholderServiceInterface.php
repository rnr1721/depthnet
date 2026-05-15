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
     * @param string $scope Scope identifier (e.g. 'global', 'preset:5')
     * @return self
     */
    public function registerPlaceholder(string $name, string $description, string $content, string $scope = 'global'): self;

    /**
     * Register multiple placeholders at once
     *
     * @param array $placeholders Array of ['name' => ['description' => '...', 'content' => '...']]
     * @param string $scope Scope identifier
     * @return self
     */
    public function registerMultiple(array $placeholders, string $scope = 'global'): self;

    /**
     * Process content by replacing all registered placeholders.
     * Placeholders are resolved by merging the given scopes in order
     * (later scopes override earlier ones for the same key).
     *
     * @param string $content Content to process
     * @param array $scopes Ordered list of scopes to merge (default: ['global'])
     * @return string Processed content with placeholders replaced
     */
    public function processContent(string $content, array $scopes = ['global']): string;

    /**
     * Get all registered placeholders for UI.
     * When multiple scopes are given, later scopes override earlier ones.
     *
     * @param array $scopes Scopes to include (default: all scopes)
     * @return array Array of ['placeholder_name' => 'description']
     */
    public function getPlaceholders(array $scopes = []): array;

    /**
     * Get placeholder content by name
     *
     * @param string $name Placeholder name (with or without brackets)
     * @param array $scopes Scopes to search (later scopes have priority), empty = all
     * @return string|null Placeholder content or null if not found
     */
    public function getPlaceholderContent(string $name, array $scopes = []): ?string;

    /**
     * Check if placeholder exists
     *
     * @param string $name Placeholder name (with or without brackets)
     * @param array $scopes Scopes to search, empty = all
     * @return bool
     */
    public function hasPlaceholder(string $name, array $scopes = []): bool;

    /**
     * Remove a placeholder
     *
     * @param string $name Placeholder name (with or without brackets)
     * @param string $scope Scope to remove from
     * @return self
     */
    public function removePlaceholder(string $name, string $scope = 'global'): self;

    /**
     * Clear all placeholders in a specific scope, or all scopes if null
     *
     * @param string|null $scope Scope to clear, null = clear everything
     * @return self
     */
    public function clear(?string $scope = null): self;

    /**
     * Get count of registered placeholders
     *
     * @param string|null $scope Scope to count, null = all scopes
     * @return int
     */
    public function count(?string $scope = null): int;

    /**
     * Preview content processing without actually changing anything
     *
     * @param string $content Content to preview
     * @param array $scopes Scopes to use for resolution
     * @return array ['original' => '...', 'processed' => '...', 'found_placeholders' => [...]]
     */
    public function previewProcessing(string $content, array $scopes = ['global']): array;

    /**
     * Register placeholder with callable content (lazy evaluation)
     *
     * @param string $name Placeholder name
     * @param string $description Description
     * @param callable $contentProvider Function that returns content when called
     * @param string $scope Scope identifier
     * @param bool $stub If true, registers as a stub (weak registration).
     *                   A stub will NOT overwrite an existing non-stub
     *                   registration for the same name in the same scope.
     *                   A non-stub registration always wins, regardless of order.
     *                   Use for plugins that declare a placeholder's existence
     *                   (for frontend discovery) but defer the actual value
     *                   to a downstream consumer.
     * @return self
     */
    public function registerDynamic(
        string $name,
        string $description,
        callable $contentProvider,
        string $scope = 'global',
        bool $stub = false
    ): self;

    /**
     * Enhanced processing that handles dynamic placeholders
     *
     * @param string $content Content to process
     * @param array $scopes Ordered list of scopes to merge
     * @return string Processed content
     */
    public function processContentWithDynamic(string $content, array $scopes = ['global']): string;
}
