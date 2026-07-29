<?php

namespace App\Services\Agent\Behavior\Enactors;

use App\Contracts\Agent\Behavior\BehaviorEnactorInterface;
use App\Models\AiPreset;
use Psr\Log\LoggerInterface;

/**
 * MoodEnactor — the first (phase 2a, only) lever kind: move a mood dimension.
 *
 * When a pattern carrying a mood lever leads the cycle, this applies its nudge via
 * MoodVectorService (additive, context-free, source-stamped) and returns the REAL
 * push so the coordinator can credit only the moment's surplus over it.
 *
 * Mirrors MoodTriggerEvaluator's discipline on the read side and the soft-nudge
 * convention Heart already uses on the write side — ABS is simply a second source
 * stamping mood, exactly as Heart stamps 'heart'. No new mechanism, a second
 * client of one that works.
 *
 * Lever JSON: { "dimension": "tenderness", "delta": 0.15 }
 */
final class MoodEnactor implements BehaviorEnactorInterface
{
    /** Source-label prefix written into the mood state's `source` field. */
    private const SOURCE_PREFIX = 'behavior:';

    public function __construct(
        protected MoodVectorService $mood,
        protected LoggerInterface $logger,
    ) {
    }

    public function kind(): string
    {
        return 'mood';
    }

    public function enact(AiPreset $preset, array $lever, string $patternName): float
    {
        $dimension = trim((string) ($lever['dimension'] ?? ''));
        if ($dimension === '') {
            return 0.0;
        }

        $delta = (float) ($lever['delta'] ?? 0.0);
        if ($delta === 0.0) {
            return 0.0;
        }

        $applied = $this->mood->nudge(
            $preset,
            $dimension,
            $delta,
            self::SOURCE_PREFIX . $patternName,
        );

        if ($applied !== 0.0) {
            $this->logger->info('MoodEnactor: lever applied', [
                'preset_id' => $preset->getId(),
                'pattern'   => $patternName,
                'dimension' => $dimension,
                'requested' => $delta,
                'applied'   => $applied,
            ]);
        }

        return $applied;
    }

    public function readDimension(AiPreset $preset, array $lever): float
    {
        $dimension = trim((string) ($lever['dimension'] ?? ''));
        if ($dimension === '') {
            return 0.0;
        }

        return $this->mood->read($preset, $dimension);
    }
}
