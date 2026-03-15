<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PlaceholderServiceInterface;

/**
 * Service for managing and processing placeholders in content.
 *
 * Supports scoped placeholders: each placeholder is registered within a named
 * scope (e.g. 'global', 'preset:5', 'tenant:abc'). When processing content,
 * an ordered list of scopes is provided — later scopes override earlier ones
 * for the same placeholder key.
 */
class PlaceholderService implements PlaceholderServiceInterface
{
    /**
     * Registered placeholders grouped by scope.
     * Format: ['scope' => ['[[placeholder_name]]' => ['content' => '...', 'description' => '...']]]
     */
    private array $scopes = [];

    /**
     * @inheritDoc
     */
    public function registerPlaceholder(string $name, string $description, string $content, string $scope = 'global'): self
    {
        $this->scopes[$scope]['[[' . $name . ']]'] = [
            'content' => $content,
            'description' => $description,
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function registerMultiple(array $placeholders, string $scope = 'global'): self
    {
        foreach ($placeholders as $name => $data) {
            $this->registerPlaceholder(
                $name,
                $data['description'] ?? '',
                $data['content'] ?? '',
                $scope
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function processContent(string $content, array $scopes = ['global']): string
    {
        $merged = $this->resolveMerged($scopes);

        if (empty($merged)) {
            return $content;
        }

        $search = array_keys($merged);
        $replace = array_column($merged, 'content');

        return str_replace($search, $replace, $content);
    }

    /**
     * @inheritDoc
     */
    public function getPlaceholders(array $scopes = []): array
    {
        $merged = empty($scopes) ? $this->resolveAll() : $this->resolveMerged($scopes);

        $result = [];
        foreach ($merged as $placeholder => $data) {
            $cleanName = trim($placeholder, '[]');
            $result[$cleanName] = $data['description'];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getPlaceholderContent(string $name, array $scopes = []): ?string
    {
        $key = $this->normalizeKey($name);
        $merged = empty($scopes) ? $this->resolveAll() : $this->resolveMerged($scopes);

        if (!isset($merged[$key])) {
            return null;
        }

        $entry = $merged[$key];

        // If dynamic, resolve the callable
        if (isset($entry['dynamic']) && $entry['dynamic'] && is_callable($entry['content'])) {
            return ($entry['content'])();
        }

        return $entry['content'];
    }

    /**
     * @inheritDoc
     */
    public function hasPlaceholder(string $name, array $scopes = []): bool
    {
        $key = $this->normalizeKey($name);
        $merged = empty($scopes) ? $this->resolveAll() : $this->resolveMerged($scopes);

        return isset($merged[$key]);
    }

    /**
     * @inheritDoc
     */
    public function removePlaceholder(string $name, string $scope = 'global'): self
    {
        $key = $this->normalizeKey($name);
        unset($this->scopes[$scope][$key]);

        // Clean up empty scope
        if (isset($this->scopes[$scope]) && empty($this->scopes[$scope])) {
            unset($this->scopes[$scope]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clear(?string $scope = null): self
    {
        if ($scope === null) {
            $this->scopes = [];
        } else {
            unset($this->scopes[$scope]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function count(?string $scope = null): int
    {
        if ($scope === null) {
            $count = 0;
            foreach ($this->scopes as $placeholders) {
                $count += count($placeholders);
            }
            return $count;
        }

        return count($this->scopes[$scope] ?? []);
    }

    /**
     * @inheritDoc
     */
    public function previewProcessing(string $content, array $scopes = ['global']): array
    {
        $merged = $this->resolveMerged($scopes);
        $foundPlaceholders = [];

        foreach ($merged as $placeholder => $data) {
            if (str_contains($content, $placeholder)) {
                $resolvedContent = $data['content'];
                if (isset($data['dynamic']) && $data['dynamic'] && is_callable($resolvedContent)) {
                    $resolvedContent = $resolvedContent();
                }

                $foundPlaceholders[] = [
                    'placeholder' => $placeholder,
                    'description' => $data['description'],
                    'content' => $resolvedContent,
                ];
            }
        }

        return [
            'original' => $content,
            'processed' => $this->processContentWithDynamic($content, $scopes),
            'found_placeholders' => $foundPlaceholders,
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerDynamic(string $name, string $description, callable $contentProvider, string $scope = 'global'): self
    {
        $this->scopes[$scope]['[[' . $name . ']]'] = [
            'content' => $contentProvider,
            'description' => $description,
            'dynamic' => true,
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function processContentWithDynamic(string $content, array $scopes = ['global']): string
    {
        $merged = $this->resolveMerged($scopes);

        if (empty($merged)) {
            return $content;
        }

        foreach ($merged as $placeholder => $data) {
            if (str_contains($content, $placeholder)) {
                $replacementContent = $data['content'];

                if (isset($data['dynamic']) && $data['dynamic'] && is_callable($replacementContent)) {
                    $replacementContent = $replacementContent();
                }

                $content = str_replace($placeholder, $replacementContent, $content);
            }
        }

        return $content;
    }

    /**
     * Merge placeholders from the given scopes in order.
     * Later scopes override earlier ones for the same key.
     *
     * @param array $scopes Ordered list of scope names
     * @return array Merged placeholders
     */
    private function resolveMerged(array $scopes): array
    {
        $merged = [];

        foreach ($scopes as $scope) {
            if (isset($this->scopes[$scope])) {
                // array_merge overwrites string keys — exactly the behavior we need
                $merged = array_merge($merged, $this->scopes[$scope]);
            }
        }

        return $merged;
    }

    /**
     * Get all placeholders across all scopes (no override priority).
     * Later-registered scopes will override earlier ones for duplicate keys.
     *
     * @return array Merged placeholders
     */
    private function resolveAll(): array
    {
        $merged = [];

        foreach ($this->scopes as $placeholders) {
            $merged = array_merge($merged, $placeholders);
        }

        return $merged;
    }

    /**
     * Normalize placeholder key (ensure it has [[ ]] brackets)
     *
     * @param string $name Placeholder name
     * @return string Normalized key with brackets
     */
    private function normalizeKey(string $name): string
    {
        if (str_starts_with($name, '[[') && str_ends_with($name, ']]')) {
            return $name;
        }

        return '[[' . $name . ']]';
    }
}
