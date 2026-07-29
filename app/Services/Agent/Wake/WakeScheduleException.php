<?php

namespace App\Services\Agent\Wake;

/**
 * Thrown when a wake schedule cannot be created or updated. The message is
 * agent-/user-readable — WakePlugin surfaces it verbatim so the model can
 * correct itself next cycle, and the CRUD controller maps it to a 422.
 */
class WakeScheduleException extends \RuntimeException
{
}
