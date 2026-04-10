<?php

namespace App\Events;

use App\Models\AiPreset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an agent uses [agent speak]...[/agent].
 * Any integration that wants to react to agent voice output listens to this event.
 */
class AgentSpeakEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $text,
        public readonly AiPreset $preset,
    ) {
    }
}
