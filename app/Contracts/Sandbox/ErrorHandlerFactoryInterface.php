<?php

namespace App\Contracts\Sandbox;

interface ErrorHandlerFactoryInterface
{
    /**
     * Create error handler for specified language
     *
     * @param string $language Language code (php, python, javascript, etc.)
     *
     * @return LanguageErrorHandlerInterface|null Handler instance or null if not found
     */
    public function create(string $language): ?LanguageErrorHandlerInterface;

    /**
     * Register new error handler for language
     *
     * @param LanguageErrorHandlerInterface $handler Handler instance
     *
     * @return self For method chaining
     */
    public function register(LanguageErrorHandlerInterface $handler): self;

    /**
     * Check if handler is registered for language
     *
     * @param string $language Language code to check
     *
     * @return bool True if handler exists
     */
    public function hasHandler(string $language): bool;

    /**
     * Get all registered language codes
     *
     * @return array<string> Array of supported language codes
     */
    public function getSupportedLanguages(): array;

}
