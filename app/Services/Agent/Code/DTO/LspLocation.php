<?php

namespace App\Services\Agent\Code\DTO;

/**
 * A location in source code (file + position).
 *
 * Used for references, definitions, symbols, etc.
 */
final readonly class LspLocation
{
    /**
     * @param  string $file    Absolute path to the file
     * @param  int    $line    Line number (0-based from LSP, 1-based displayed)
     * @param  int    $col     Column number (0-based)
     * @param  string $context Optional surrounding code for context
     */
    public function __construct(
        public string $file,
        public int $line = 0,
        public int $col = 0,
        public string $context = '',
    ) {
    }

    /**
     * Human-readable position string.
     */
    public function position(): string
    {
        if ($this->line === 0) {
            return $this->file;
        }

        $pos = $this->file . ':' . $this->line;
        if ($this->col > 0) {
            $pos .= ':' . $this->col;
        }

        return $pos;
    }
}
