<?php

namespace App\Contracts\Logs;

interface LogViewerServiceInterface
{
    /**
     * List readable *.log files in storage/logs, newest first.
     *
     * @return array<int, array{name: string, size: int, modified: int}>
     */
    public function listFiles(): array;

    /**
     * Read a log file: the tail (offset = null) or everything appended after $offset.
     *
     * @return array{
     *     file: ?string,
     *     size: int,
     *     offset: int,
     *     reset: bool,
     *     truncated: bool,
     *     orphan: ?string,
     *     entries: array<int, array{datetime: string, env: string, level: string, message: string, details: ?string}>
     * }
     */
    public function read(?string $file, ?int $offset): array;

    /**
     * Truncate a log file to zero length.
     */
    public function clear(string $file): bool;
}
