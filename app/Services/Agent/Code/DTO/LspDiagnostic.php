<?php

namespace App\Services\Agent\Code\DTO;

/**
 * A diagnostic message from the LSP server (error, warning, hint).
 */
final readonly class LspDiagnostic
{
    /**
     * @param  string $file     Absolute path to the file
     * @param  int    $line     Line number
     * @param  string $message  Diagnostic message
     * @param  string $severity "error", "warning", or "info"
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $message,
        public string $severity = 'error',
    ) {
    }

    /**
     * Icon for the severity level.
     */
    public function icon(): string
    {
        return match ($this->severity) {
            'error'   => '❌',
            'warning' => '⚠️',
            default   => 'ℹ️',
        };
    }
}
