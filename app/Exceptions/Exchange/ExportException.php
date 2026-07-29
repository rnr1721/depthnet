<?php

namespace App\Exceptions\Exchange;

/**
 * Thrown when a bundle cannot be produced — a missing preset/agent, or an
 * unexportable reference (e.g. a spawned/ephemeral preset caught in the
 * export closure). Fail-closed: better a clear refusal than a bundle that
 * won't reproduce on the far side.
 */
class ExportException extends \RuntimeException
{
}
