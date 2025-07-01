<?php

declare(strict_types=1);

namespace App\Services\Sandbox\ErrorHandlers;

use App\Contracts\Sandbox\LanguageErrorHandlerInterface;

/**
 * Python error handler (placeholder for future implementation)
 * TODO: need check carefully and improve
 */
class PythonErrorHandler implements LanguageErrorHandlerInterface
{
    /**
     * @inheritDoc
     */
    public function prepareCode(string $code): string
    {
        // Python traceback filtering can be added here
        return $code;
    }

    /**
     * @inheritDoc
     */
    public function buildExecutionCommand(string $interpreter, string $filePath): string
    {
        return "{$interpreter} {$filePath} 2>&1 | " . $this->getPythonErrorFilter($filePath);
    }

    /**
     * @inheritDoc
     */
    public function getLanguageCode(): string
    {
        return 'python';
    }

    /**
     * Generate sed command to filter Python errors
     */
    private function getPythonErrorFilter(string $filePath): string
    {
        $filters = [
            // File "/tmp/code_12345.py" -> File "script.py"
            "s|File \"{$filePath}\"|File \"script.py\"|g",

            // /tmp/code_12345.py:5: -> script.py:5:
            "s|{$filePath}:|script.py:|g",

            // Remove full paths from other messages
            "s|{$filePath}|script.py|g"
        ];

        return 'sed \'' . implode('; ', $filters) . '\'';
    }
}
