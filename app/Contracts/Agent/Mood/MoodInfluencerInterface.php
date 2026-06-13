<?php

namespace App\Contracts\Agent\Mood;

/**
 * MoodInfluencerInterface — contract for pushing weak emotional signals into mood state.
 *
 * Implemented by MoodPlugin. Injected (optionally) into HeartPlugin so that
 * heart signals can nudge mood without creating a hard circular dependency.
 *
 * If MoodPlugin is disabled or not bound, HeartPlugin simply receives null
 * and skips the push — no errors, no coupling.
 */
interface MoodInfluencerInterface
{
    /**
     * Push a weak signal into the mood state.
     *
     * The signal does NOT override current state — it nudges intensity
     * by a fraction of $intensity. The mood system applies its own
     * scaling (typically * 0.3) to keep heart-sourced signals soft.
     *
     * @param string $emotion  Emotion name (arbitrary string, not enum-bound)
     * @param float  $intensity Signal intensity from heart (0.0 – 1.0)
     * @param string $source   Source label, defaults to 'heart'
     */
    public function pushSignal(string $emotion, float $intensity, string $source = 'heart'): void;
}
