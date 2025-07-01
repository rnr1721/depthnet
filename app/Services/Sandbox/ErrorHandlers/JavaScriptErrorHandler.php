<?php

namespace App\Services\Sandbox\ErrorHandlers;

use App\Contracts\Sandbox\LanguageErrorHandlerInterface;

/**
 * JavaScript error handler (placeholder for future implementation)
 * TODO: need check carefully and improve
 */
class JavaScriptErrorHandler implements LanguageErrorHandlerInterface
{
    /**
     * @inheritDoc
     */
    public function prepareCode(string $code): string
    {
        // Node.js error handling here
        return $code;
    }

    /**
     * @inheritDoc
     */
    public function buildExecutionCommand(string $interpreter, string $filePath): string
    {
        // Could add similar path filtering
        return "{$interpreter} {$filePath} 2>&1 | " . $this->getNodeErrorFilter($filePath);
    }

    /**
     * @inheritDoc
     */
    public function getLanguageCode(): string
    {
        return 'node';
    }

    /**
     * Generate sed command to filter Node.js errors
     */
    private function getNodeErrorFilter(string $filePath): string
    {
        $filters = [
            // Replace the path
            "s|{$filePath}|script.js|g",

            // Remove node:internal (Node.js internals)
            '/node:internal/d',

            // Remove Module._compile, _extensions, etc.
            '/Module\._compile/d',
            '/Module\._extensions/d',
            '/Module\.load/d',
            '/Module\._load/d',
            '/executeUserEntryPoint/d',

            '/Node\.js v[0-9]/d',

            '/^$/d'
        ];

        return 'sed \'' . implode('; ', $filters) . '\'';
    }
}
