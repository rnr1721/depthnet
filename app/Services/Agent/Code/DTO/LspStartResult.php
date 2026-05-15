<?php

namespace App\Services\Agent\Code\DTO;

/**
 * Result of an LSP server start attempt.
 */
final readonly class LspStartResult
{
    /**
     * @param  bool   $success  Whether the server started successfully
     * @param  string $message  Human-readable status message
     * @param  string $language Detected language (e.g. "php", "go", "typescript"), empty on failure
     * @param  int    $pid      Process ID of the LSP server, 0 on failure
     */
    public function __construct(
        public bool $success,
        public string $message,
        public string $language = '',
        public int $pid = 0,
    ) {
    }
}
