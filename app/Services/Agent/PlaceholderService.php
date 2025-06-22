<?php

namespace App\Services\Agent;

use App\Contracts\Agent\PlaceholderServiceInterface;

/**
 * Service for managing and processing placeholders in content
 *
 * Allows registering placeholders throughout the application lifecycle
 * and then processing them in any content string
 */
class PlaceholderService implements PlaceholderServiceInterface
{
    /**
     * Registered placeholders
     * Format: ['[[placeholder_name]]' => ['content' => '...', 'description' => '...']]
     */
    private array $placeholders = [];

    /**
     * @inheritDoc
     */
    public function registerPlaceholder(string $name, string $description, string $content): self
    {
        $this->placeholders['[[' . $name . ']]'] = [
            'content' => $content,
            'description' => $description
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function registerMultiple(array $placeholders): self
    {
        foreach ($placeholders as $name => $data) {
            $this->registerPlaceholder(
                $name,
                $data['description'] ?? '',
                $data['content'] ?? ''
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function processContent(string $content): string
    {
        if (empty($this->placeholders)) {
            return $content;
        }

        $search = array_keys($this->placeholders);
        $replace = array_column($this->placeholders, 'content');

        return $this->processPlaceholders($search, $replace, $content);
    }

    /**
     * @inheritDoc
     */
    public function getPlaceholders(): array
    {
        $result = [];

        foreach ($this->placeholders as $placeholder => $data) {
            // Remove [[ ]] brackets for UI
            $cleanName = trim($placeholder, '[]');
            $result[$cleanName] = $data['description'];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getPlaceholderContent(string $name): ?string
    {
        $key = $this->normalizeKey($name);
        return $this->placeholders[$key]['content'] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function hasPlaceholder(string $name): bool
    {
        $key = $this->normalizeKey($name);
        return isset($this->placeholders[$key]);
    }

    /**
     * @inheritDoc
     */
    public function removePlaceholder(string $name): self
    {
        $key = $this->normalizeKey($name);
        unset($this->placeholders[$key]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clear(): self
    {
        $this->placeholders = [];
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        return count($this->placeholders);
    }

    /**
     * @inheritDoc
     */
    public function previewProcessing(string $content): array
    {
        $foundPlaceholders = [];

        foreach ($this->placeholders as $placeholder => $data) {
            if (str_contains($content, $placeholder)) {
                $foundPlaceholders[] = [
                    'placeholder' => $placeholder,
                    'description' => $data['description'],
                    'content' => $data['content']
                ];
            }
        }

        return [
            'original' => $content,
            'processed' => $this->processContent($content),
            'found_placeholders' => $foundPlaceholders
        ];
    }

    /**
     * Normalize placeholder key (ensure it has [[ ]] brackets)
     *
     * @param string $name Placeholder name
     * @return string Normalized key with brackets
     */
    private function normalizeKey(string $name): string
    {
        // If already has brackets, return as is
        if (str_starts_with($name, '[[') && str_ends_with($name, ']]')) {
            return $name;
        }

        // Add brackets
        return '[[' . $name . ']]';
    }

    /**
     * Process placeholders replacement
     *
     * @param array $search Array of placeholder strings to search for
     * @param array $replace Array of replacement strings
     * @param string $content Content to process
     * @return string Processed content
     */
    private function processPlaceholders(array $search, array $replace, string $content): string
    {
        return str_replace($search, $replace, $content);
    }

    /**
     * @inheritDoc
     */
    public function registerDynamic(string $name, string $description, callable $contentProvider): self
    {
        // Store the callable, it will be executed when content is processed
        $this->placeholders['[[' . $name . ']]'] = [
            'content' => $contentProvider,
            'description' => $description,
            'dynamic' => true
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function processContentWithDynamic(string $content): string
    {
        if (empty($this->placeholders)) {
            return $content;
        }

        foreach ($this->placeholders as $placeholder => $data) {
            if (str_contains($content, $placeholder)) {
                $replacementContent = $data['content'];

                // If it's a dynamic placeholder, call the function
                if (isset($data['dynamic']) && $data['dynamic'] && is_callable($replacementContent)) {
                    $replacementContent = $replacementContent();
                }

                $content = str_replace($placeholder, $replacementContent, $content);
            }
        }

        return $content;
    }
}
