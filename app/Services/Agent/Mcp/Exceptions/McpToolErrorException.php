<?php

namespace App\Services\Agent\Mcp\Exceptions;

/**
 * The server answered, but this tool call failed: either a JSON-RPC error
 * (unknown tool, invalid params) or result.isError = true (the tool ran and
 * reported failure — upstream API error, bad input...).
 *
 * Distinct from transport errors: the SERVER is reachable and healthy.
 * Callers must not mark the server unhealthy for this.
 */
class McpToolErrorException extends \RuntimeException
{
}
