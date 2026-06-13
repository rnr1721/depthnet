<?php

namespace App\Services\Agent\Enricher\Rag\Renderers\Concerns;

use App\Contracts\Agent\PulseServiceInterface;
use Carbon\Carbon;

/**
 * Shared pulse-label formatting for RAG section renderers.
 *
 * Renderers that have temporally anchored items (memory entries with
 * createdAt, journal entries with recordedAt) can compose this trait
 * to gain a uniform "day N pulse M" coordinate label for those moments.
 *
 * The trait expects the host class to expose a PulseServiceInterface
 * instance via the property name `pulse`. Concrete renderers should
 * inject the service through their constructor and either store it as
 * `protected readonly PulseServiceInterface $pulse` or assign it to
 * `$this->pulse` in __construct.
 *
 * Activation is driven by two render options:
 *   - 'show_pulse_date' : bool — whether to emit the label at all
 *   - 'agent_birth_date': string|null — ISO date the pulse counts from
 *
 * When show_pulse_date is false (or the option is missing), the helper
 * returns null and the renderer should skip the label entirely.
 *
 * When agent_birth_date is missing or empty, the PulseService falls
 * back to its own default (currently Unix epoch). This is intentional:
 * if a preset enables pulse_dates without setting a birth date, the
 * agent will see absurdly large day-of-life numbers, which is a clear
 * "configure me" signal rather than a silent feature regression.
 *
 * @property PulseServiceInterface $pulse  Service for calculating pulse coordinates.
 */
trait FormatsPulseLabel
{
    /**
     * Build the pulse coordinate label for a given moment.
     *
     * Returns null when:
     *   - the render options don't request a pulse label
     *   - the moment is null (item has no anchored date)
     *
     * Otherwise returns a compact "day N pulse M" string.
     *
     * @param  Carbon|\DateTimeInterface|null  $moment   Anchor moment of the item.
     * @param  array  $options  Render options from the section.
     * @return string|null  Compact label or null when not applicable.
     */
    protected function formatPulseLabel(Carbon|\DateTimeInterface|null $moment, array $options): ?string
    {
        if (empty($options['show_pulse_date'])) {
            return null;
        }

        if ($moment === null) {
            return null;
        }

        // Defensive: PulseService API uses Carbon; promote a vanilla
        // DateTimeInterface to Carbon if necessary.
        if (!$moment instanceof Carbon) {
            $moment = Carbon::instance($moment);
        }

        $birthDate = (string) ($options['agent_birth_date'] ?? '');

        $day   = $this->pulse->dayOfLife($birthDate, $moment);
        $pulse = $this->pulse->currentPulse($moment);

        if ($day === null) {
            // No biographical anchor at all — show only the cyclic pulse.
            // Better than nothing: still conveys the time-of-day flavour.
            return 'pulse ' . $pulse;
        }

        return 'day ' . $day . ' pulse ' . $pulse;
    }
}
