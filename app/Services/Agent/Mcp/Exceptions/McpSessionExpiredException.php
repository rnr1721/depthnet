<?php

namespace App\Services\Agent\Mcp\Exceptions;

/**
 * The server answered 404 to a request that carried an Mcp-Session-Id:
 * per the Streamable HTTP spec the session is gone and the client MUST
 * start a new one with a fresh initialize.
 */
class McpSessionExpiredException extends \RuntimeException
{
}
