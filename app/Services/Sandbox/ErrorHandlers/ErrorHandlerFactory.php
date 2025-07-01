<?php

declare(strict_types=1);

namespace App\Services\Sandbox\ErrorHandlers;

use App\Contracts\Sandbox\ErrorHandlerFactoryInterface;
use App\Contracts\Sandbox\LanguageErrorHandlerInterface;
use InvalidArgumentException;

/**
 * Factory for creating language-specific error handlers
 *
 * This factory manages a collection of error handlers for different programming
 * languages and provides them on demand. Handlers can be injected via constructor
 * for dependency injection or created dynamically.
 */
class ErrorHandlerFactory implements ErrorHandlerFactoryInterface
{
    /**
     * @var array<string, LanguageErrorHandlerInterface> Map of language codes to handlers
     */
    private array $errorHandlers = [];

    /**
     * @inheritDoc
     */
    public function __construct(array $errorHandlers = [])
    {
        foreach ($errorHandlers as $errorHandler) {
            if (!($errorHandler instanceof LanguageErrorHandlerInterface)) {
                throw new InvalidArgumentException(
                    'You need to inject true LanguageErrorHandlerInterface! Please go to provider and fix it!'
                );
            }

            $language = $errorHandler->getLanguageCode();
            $this->errorHandlers[$language] = $errorHandler;
        }
    }

    /**
     * @inheritDoc
     */
    public function create(string $language): ?LanguageErrorHandlerInterface
    {
        return $this->errorHandlers[$language] ?? $this->createDefaultHandler($language);
    }

    /**
     * @inheritDoc
     */
    public function register(LanguageErrorHandlerInterface $handler): self
    {
        $language = $handler->getLanguageCode();
        $this->errorHandlers[$language] = $handler;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function hasHandler(string $language): bool
    {
        return array_key_exists($language, $this->errorHandlers);
    }

    /**
     * @inheritDoc
     */
    public function getSupportedLanguages(): array
    {
        return array_keys($this->errorHandlers);
    }

    /**
     * Create default handlers for common languages
     *
     * @param string $language Language code
     *
     * @return LanguageErrorHandlerInterface|null Default handler or null
     */
    private function createDefaultHandler(string $language): ?LanguageErrorHandlerInterface
    {
        return match ($language) {
            'php' => new PHPErrorHandler(),
            'python' => new PythonErrorHandler(),
            'javascript', 'node' => new JavaScriptErrorHandler(),
            default => null
        };
    }
}
