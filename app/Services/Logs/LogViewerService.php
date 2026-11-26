<?php

namespace App\Services\Logs;

use App\Contracts\Logs\LogViewerServiceInterface;
use InvalidArgumentException;

/**
 * Reads Laravel log files without loading them whole into memory:
 * seeks from the end for the initial tail, then reads only appended bytes.
 *
 * Works with both `single` (laravel.log) and `daily` (laravel-YYYY-MM-DD.log) channels.
 */
class LogViewerService implements LogViewerServiceInterface
{
    /** Initial tail size. */
    protected const TAIL_BYTES = 256 * 1024;

    /** Max bytes per incremental read; bigger gaps are skipped to the tail. */
    protected const MAX_CHUNK_BYTES = 1024 * 1024;

    /** Max entries returned per response. */
    protected const MAX_ENTRIES = 1500;

    /** Max length of a single entry's details (huge context dumps). */
    protected const MAX_DETAILS = 20000;

    /** [2026-11-26 21:16:03] local.ERROR: message ... */
    protected const HEADER_RE = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+([\w\-]+)\.([A-Za-z]+):\s?(.*)$/';

    protected string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? storage_path('logs'), DIRECTORY_SEPARATOR);
    }

    public function listFiles(): array
    {
        $files = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $files[] = [
                'name'     => basename($path),
                'size'     => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    public function read(?string $file, ?int $offset): array
    {
        $files = $this->listFiles();
        $file  = $file ?: ($files[0]['name'] ?? null);

        $empty = [
            'file' => $file, 'size' => 0, 'offset' => 0, 'reset' => $offset !== null,
            'truncated' => false, 'orphan' => null, 'entries' => [],
        ];

        if (!$file) {
            return $empty;
        }

        $path = $this->resolve($file);
        clearstatcache(true, $path);
        $size = (int) filesize($path);

        $reset     = false;
        $truncated = false;

        // Rotated / truncated since last read -> start over from the tail.
        if ($offset !== null && $offset > $size) {
            $offset = null;
            $reset  = true;
        }

        if ($offset === null) {
            $start     = max(0, $size - static::TAIL_BYTES);
            $truncated = $start > 0;
        } else {
            $start = $offset;
            if ($size - $start > static::MAX_CHUNK_BYTES) {
                $start     = $size - static::TAIL_BYTES;
                $reset     = true;
                $truncated = true;
            }
        }

        if ($start >= $size) {
            return array_merge($empty, ['size' => $size, 'offset' => $size, 'reset' => $reset]);
        }

        $chunk = $this->readRange($path, $start, $size - $start);

        // Only complete lines: the last line may still be being written.
        $lastNl = strrpos($chunk, "\n");
        if ($lastNl === false) {
            return array_merge($empty, ['size' => $size, 'offset' => $start, 'reset' => $reset]);
        }
        $chunk     = substr($chunk, 0, $lastNl + 1);
        $newOffset = $start + strlen($chunk);

        // Tail read started mid-file: drop the partial first line.
        $isTailRead = $offset === null || $reset;
        if ($isTailRead && $start > 0) {
            $firstNl = strpos($chunk, "\n");
            $chunk   = $firstNl === false ? '' : substr($chunk, $firstNl + 1);
        }

        [$entries, $orphan] = $this->parse($chunk);

        // On a tail read, leading orphan lines have no parent -> drop them.
        if ($isTailRead) {
            $orphan = null;
        }

        if (count($entries) > static::MAX_ENTRIES) {
            $entries   = array_slice($entries, -static::MAX_ENTRIES);
            $truncated = true;
        }

        return [
            'file'      => $file,
            'size'      => $size,
            'offset'    => $newOffset,
            'reset'     => $reset,
            'truncated' => $truncated,
            'orphan'    => $orphan,
            'entries'   => $entries,
        ];
    }

    public function clear(string $file): bool
    {
        $path = $this->resolve($file);

        $fh = fopen($path, 'c');
        if (!$fh) {
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            $ok = ftruncate($fh, 0);
            flock($fh, LOCK_UN);
            return $ok;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Validate file name and resolve it strictly inside the logs directory.
     */
    protected function resolve(string $file): string
    {
        if (!preg_match('/^[A-Za-z0-9._\-]+\.log$/', $file)) {
            throw new InvalidArgumentException('Invalid log file name');
        }

        $path = realpath($this->dir . DIRECTORY_SEPARATOR . $file);
        $dir  = realpath($this->dir);

        if ($path === false || $dir === false || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new InvalidArgumentException('Log file not found');
        }

        return $path;
    }

    protected function readRange(string $path, int $start, int $length): string
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        try {
            fseek($fh, $start);
            $data = '';
            while ($length > 0 && !feof($fh)) {
                $buf = fread($fh, min(65536, $length));
                if ($buf === false || $buf === '') {
                    break;
                }
                $data   .= $buf;
                $length -= strlen($buf);
            }
            return $data;
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return array{0: array<int, array>, 1: ?string} [entries, orphan lines before first header]
     */
    protected function parse(string $chunk): array
    {
        $entries = [];
        $orphan  = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $chunk) as $line) {
            if (preg_match(static::HEADER_RE, $line, $m)) {
                if ($current) {
                    $entries[] = $this->finish($current);
                }
                $current = [
                    'datetime' => $m[1],
                    'env'      => $m[2],
                    'level'    => strtolower($m[3]),
                    'first'    => $m[4],
                    'lines'    => [],
                ];
                continue;
            }

            if ($current) {
                $current['lines'][] = $line;
            } elseif ($line !== '') {
                $orphan[] = $line;
            }
        }

        if ($current) {
            $entries[] = $this->finish($current);
        }

        $orphanText = $orphan ? $this->cut(implode("\n", $orphan)) : null;

        return [$entries, $orphanText];
    }

    protected function finish(array $e): array
    {
        $first = $e['first'];

        // Laravel puts the JSON context on the first line: "Message {"exception":...}"
        // Short message in `message`, full text in `details`.
        $message = $first;
        $context = null;
        if (preg_match('/^(.*?)\s(\{.*\}|\[.*\])\s*$/s', $first, $m) && strlen($m[1]) > 0) {
            $message = $m[1];
            $context = $m[2];
        } elseif (($pos = strpos($first, ' {"')) !== false && $pos > 0) {
            // Multi-line context (exceptions): JSON starts here, closes lines later
            $message = substr($first, 0, $pos);
            $context = substr($first, $pos + 1);
        }

        $rest    = rtrim(implode("\n", $e['lines']));
        $details = trim(($context ?? '') . ($rest !== '' ? "\n" . $rest : ''));

        return [
            'datetime' => $e['datetime'],
            'env'      => $e['env'],
            'level'    => $e['level'],
            'message'  => mb_strimwidth($message, 0, 1000, '…'),
            'details'  => $details !== '' ? $this->cut($details) : null,
        ];
    }

    protected function cut(string $s): string
    {
        return strlen($s) > static::MAX_DETAILS
            ? substr($s, 0, static::MAX_DETAILS) . "\n… (" . (strlen($s) - static::MAX_DETAILS) . ' more bytes)'
            : $s;
    }
}
