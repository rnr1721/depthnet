<?php

namespace App\Listeners;

use App\Contracts\Integrations\Rhasspy\RhasspyServiceInterface;
use App\Events\AgentSpeakEvent;
use Psr\Log\LoggerInterface;

/**
 * Reacts to AgentSpeakEvent and forwards the text to Rhasspy TTS
 * if the integration is enabled for the preset that fired the event.
 *
 * Failures are fully isolated — any exception here must not propagate
 * back to the agent cycle.
 */
class RhasspySpeakListener
{
    public function __construct(
        private readonly RhasspyServiceInterface $rhasspyService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(AgentSpeakEvent $event): void
    {
        try {
            $this->rhasspyService->speakForPreset($event->text, $event->preset);
        } catch (\Throwable $e) {
            // Last-resort catch — speakForPreset already catches internally,
            // but we double-fence here to be 100% sure nothing leaks up.
            $this->logger->error('RhasspySpeakListener: unhandled exception', [
                'preset_id' => $event->preset->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
