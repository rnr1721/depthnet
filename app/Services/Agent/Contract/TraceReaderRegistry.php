<?php

namespace App\Services\Agent\Contract;

use App\Contracts\Agent\Contract\TraceReaderInterface;

/**
 * TraceReaderRegistry — maps a contract's match.source to the reader that serves
 * it.
 *
 * Readers are supplied via a tagged binding (see the service provider), so a new
 * source is added by registering one more reader — nothing here or in the engine
 * changes. forSource() returns null for an unknown source; the engine treats a
 * contract with no reader as unsatisfiable and skips it.
 */
class TraceReaderRegistry
{
    /** @var array<string, TraceReaderInterface> */
    private array $bySource = [];

    /**
     * @param iterable<TraceReaderInterface> $readers
     */
    public function __construct(iterable $readers = [])
    {
        foreach ($readers as $reader) {
            $this->register($reader);
        }
    }

    public function register(TraceReaderInterface $reader): void
    {
        $this->bySource[$reader->source()] = $reader;
    }

    public function forSource(string $source): ?TraceReaderInterface
    {
        return $this->bySource[$source] ?? null;
    }

    /**
     * @return string[] sources with a registered reader
     */
    public function sources(): array
    {
        return array_keys($this->bySource);
    }
}
