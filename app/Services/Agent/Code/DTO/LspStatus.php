<?php

namespace App\Services\Agent\Code\DTO;

/**
 * Current LSP server status.
 */
final readonly class LspStatus
{
    /**
     * @param  bool   $running  Whether the server is running
     * @param  string $language Detected language, empty if not running
     * @param  int    $pid      Process ID, 0 if not running
     */
    public function __construct(
        public bool $running,
        public string $language = '',
        public int $pid = 0,
    ) {
    }
}
