<?php

namespace App\Exceptions;

/**
 * An error whose message is written FOR THE MODEL.
 *
 * Throw it from a plugin method instead of returning an "Error: ..." string.
 * CommandExecutor turns it into a failed CommandResult with the message as-is:
 * the model sees the ERROR header (not SUCCESS), tool_calls mode gets
 * is_error on the tool_result, and nothing is logged as a system failure —
 * a wrong argument from the model is not an application error.
 *
 * Plugins can migrate gradually: returning an error string keeps working.
 */
class PluginUserException extends \RuntimeException
{
}
