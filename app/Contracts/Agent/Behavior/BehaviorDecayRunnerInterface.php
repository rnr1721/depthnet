<?php

namespace App\Contracts\Agent\Behavior;

/**
 * BehaviorDecayRunnerInterface — drives ABS inactivity decay across presets on
 * its own clock (the second tick-hand), mirroring the contract metabolism.
 */
interface BehaviorDecayRunnerInterface
{
    /**
     * Decay every ABS-enabled preset. One row per considered preset.
     *
     * @return array<int, array{preset_id:int, preset:string, outcome:string, detail:string}>
     */
    public function decayAll(float $factor = 0.98, int $idleGrace = 3): array;

    /**
     * Decay a single preset by id (debugging). Null when not found or ABS off.
     *
     * @return array{preset_id:int, preset:string, outcome:string, detail:string}|null
     */
    public function decayPreset(int $presetId, float $factor = 0.98, int $idleGrace = 3): ?array;
}
