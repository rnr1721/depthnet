<?php

declare(strict_types=1);

namespace App\Contracts\Sandbox;

/**
 * Interface for language-specific error handlers in sandbox environment
 *
 * This interface defines the contract for handling errors and preparing code
 * execution for different programming languages. Each language handler is
 * responsible for:
 *
 * - Preparing user code with custom error handlers
 * - Building execution commands with proper error formatting
 * - Ensuring clean error output without exposing temporary file paths
 *
 * @package App\Contracts\Sandbox
 * @author Your Friendly Neighborhood Developer
 * @version 1.0.0
 * @since 2025-07-01
 *
 * @example
 * ```php
 * $handler = new PHPErrorHandler();
 * $preparedCode = $handler->prepareCode('<?php myfunction();');
 * $command = $handler->buildExecutionCommand('php', '/tmp/script.php');
 * ```
 */
interface LanguageErrorHandlerInterface
{
    /**
     * Prepare user code with language-specific error handling
     *
     * This method takes raw user code and wraps it with custom error handlers
     * that provide clean, formatted error messages without exposing internal
     * file paths or implementation details.
     *
     * For PHP, this includes:
     * - Custom set_error_handler() for runtime errors
     * - Custom set_exception_handler() for uncaught exceptions
     * - Automatic line number adjustment to show user code lines
     * - Rich error type mapping (including the legendary "PIZDETS" for case 0)
     *
     * For other languages, this may include:
     * - Python: Custom exception formatting
     * - JavaScript: Custom error formatting for Node.js
     * - Any language-specific error handling needs
     *
     * @param string $code Raw user code to be executed
     *
     * @return string Code with error handling wrapper prepended
     *
     * @throws \InvalidArgumentException When code is empty or invalid
     *
     * @example
     * ```php
     * // Input
     * $userCode = 'myfunction();';
     *
     * // Output (simplified)
     * $preparedCode = '<?php
     * set_error_handler(function($errno, $errstr, $file, $line) {
     *     // Custom error handling logic
     * });
     * myfunction();';
     * ```
     */
    public function prepareCode(string $code): string;

    /**
     * Build execution command with error output formatting
     *
     * This method constructs the shell command used to execute the prepared
     * code file. It should include any necessary flags, options, or pipe
     * operations to ensure clean error output.
     *
     * The command should:
     * - Execute the code file with the specified interpreter
     * - Format error output to hide temporary file paths
     * - Preserve useful debugging information (line numbers, error types)
     * - Handle both parse-time and runtime errors appropriately
     *
     * @param string $interpreter Path or name of the language interpreter
     *                           (e.g., 'php', '/usr/bin/python3', 'node')
     * @param string $filePath Full path to the temporary code file to execute
     *
     * @return string Complete shell command ready for execution
     *
     * @example
     * ```php
     * // PHP example
     * $command = $handler->buildExecutionCommand('php', '/tmp/code_12345.php');
     * // Returns: "php -d display_errors=1 -d log_errors=0 /tmp/code_12345.php"
     *
     * // Python example
     * $command = $handler->buildExecutionCommand('python3', '/tmp/script_67890.py');
     * // Returns: "python3 /tmp/script_67890.py 2>&1 | sed 's|/tmp/script_.*\.py|script.py|g'"
     * ```
     */
    public function buildExecutionCommand(string $interpreter, string $filePath): string;

    /**
     * Return Language Code - php, python, node etc
     *
     * @return string
     */
    public function getLanguageCode(): string;
}
