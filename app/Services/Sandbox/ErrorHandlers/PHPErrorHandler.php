<?php

declare(strict_types=1);

namespace App\Services\Sandbox\ErrorHandlers;

use App\Contracts\Sandbox\LanguageErrorHandlerInterface;

/**
 * BDSM PHP Error Handler - Automatic Line Counting via Templates
 * TODO: need check carefully and improve
 */
class PHPErrorHandler implements LanguageErrorHandlerInterface
{
    /**
     * @inheritDoc
     */
    public function prepareCode(string $code): string
    {
        $errorHandler = $this->getErrorHandlerCode();
        $userCode = $this->cleanUserCode($code);

        return $errorHandler . $userCode;
    }

    /**
     * @inheritDoc
     */
    public function buildExecutionCommand(string $interpreter, string $filePath): string
    {
        return "{$interpreter} -d display_errors=1 -d log_errors=0 -d html_errors=0 {$filePath}";
    }

    /**
     * @inheritDoc
     */
    private function getErrorHandlerCode(): string
    {
        $handlerTemplate = '<?php
$handlerLines = {HANDLER_LINES}; // Auto-calculated line count

set_error_handler(function($errno, $errstr, $errfile, $errline) use ($handlerLines) {
    $errorTypes = [
        0 => "PIZDETS",                    // When everything goes wrong
        E_ERROR => "E_ERROR",              // Fatal errors
        E_WARNING => "E_WARNING",          // Warnings
        E_PARSE => "E_PARSE",              // Parse errors
        E_NOTICE => "E_NOTICE",            // Notices
        E_CORE_ERROR => "E_CORE_ERROR",    // Core errors
        E_CORE_WARNING => "E_CORE_WARNING", // Core warnings
        E_COMPILE_ERROR => "E_COMPILE_ERROR", // Compile errors
        E_COMPILE_WARNING => "E_COMPILE_WARNING", // Compile warnings
        E_USER_ERROR => "E_USER_ERROR",    // User errors
        E_USER_WARNING => "E_USER_WARNING", // User warnings
        E_USER_NOTICE => "E_USER_NOTICE",  // User notices
        E_STRICT => "E_STRICT",            // Strict standards
        E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR", // Recoverable errors
        E_DEPRECATED => "E_DEPRECATED",    // Deprecated features
        E_USER_DEPRECATED => "E_USER_DEPRECATED" // User deprecated
    ];
    
    $type = $errorTypes[$errno] ?? "UNKNOWN_ERROR";
    $userLine = $errline - $handlerLines;
    echo "$type: $errstr on line $userLine\\n";
    return true; // Suppress default PHP error output
});

set_exception_handler(function($exception) use ($handlerLines) {
    $line = $exception->getLine() - $handlerLines;
    echo "Fatal error: Uncaught " . get_class($exception) . ": " . $exception->getMessage() . " on line $line\\n";
});

';

        // BDSM magic: aVtOmAtIc StRinG counting!
        $handlerLines = substr_count($handlerTemplate, "\n");

        return str_replace('{HANDLER_LINES}', (string)$handlerLines, $handlerTemplate);
    }

    private function cleanUserCode(string $code): string
    {
        return preg_replace('/^\s*<\?php\s*/', '', trim($code));
    }

    /**
     * @inheritDoc
     */
    public function getLanguageCode(): string
    {
        return 'php';
    }
}
