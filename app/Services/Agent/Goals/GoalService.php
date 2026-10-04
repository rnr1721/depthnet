<?php

namespace App\Services\Agent\Goals;

use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\Heart\HeartServiceInterface;
use App\Models\AiPreset;
use App\Models\Goal;
use App\Models\GoalProgress;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class GoalService implements GoalServiceInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected Goal $goalModel,
        protected GoalProgress $goalProgressModel,
        protected HeartServiceInterface $heartService,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addGoal(AiPreset $preset, string $title, ?string $motivation): array
    {
        try {
            $title = trim($title);
            if (empty($title)) {
                return ['success' => false, 'message' => 'Error: Goal title cannot be empty.'];
            }

            // Position after the current max (not count+1): stays monotonic even
            // after individual goals are deleted from the UI.
            $position = ((int) $this->goalModel->forPreset($preset->id)->max('position')) + 1;

            $goal = $this->goalModel->create([
                'preset_id'  => $preset->id,
                'title'      => $title,
                'motivation' => $motivation ? trim($motivation) : null,
                'status'     => Goal::STATUS_ACTIVE,
                'position'   => $position,
            ]);

            $number = $this->numberOf($preset, $goal) ?? $position;

            return [
                'success' => true,
                'message' => "Goal [{$number}] created: {$title}"
                    . ($motivation ? " | motivation: {$motivation}" : ''),
            ];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::addGoal error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error creating goal: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function addProgress(AiPreset $preset, int $goalNumber, string $content): array
    {
        try {
            $goal = $this->getGoalByNumber($preset, $goalNumber);
            if (!$goal) {
                return ['success' => false, 'message' => "Error: Goal [{$goalNumber}] not found."];
            }

            $content = trim($content);
            if (empty($content)) {
                return ['success' => false, 'message' => 'Error: Progress note cannot be empty.'];
            }

            $this->goalProgressModel->create([
                'goal_id' => $goal->id,
                'content' => $content,
            ]);

            return [
                'success' => true,
                'message' => "Progress noted for goal [{$goalNumber}]: {$content}",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::addProgress error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error adding progress: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function setStatus(AiPreset $preset, int $goalNumber, string $status): array
    {
        try {
            $goal = $this->getGoalByNumber($preset, $goalNumber);
            if (!$goal) {
                return ['success' => false, 'message' => "Error: Goal [{$goalNumber}] not found."];
            }

            if (!in_array($status, Goal::STATUSES, true)) {
                return [
                    'success' => false,
                    'message' => 'Error: Invalid status. Use: ' . implode(', ', Goal::STATUSES) . '.',
                ];
            }

            $wasFocused = $goal->isFocused();
            $update     = ['status' => $status];

            // Focus only makes sense for a goal being pursued. Leaving 'active'
            // in any direction (done, dropped, paused) puts it down.
            if ($status !== Goal::STATUS_ACTIVE) {
                $update['focused_at'] = null;
            }

            $goal->update($update);

            $this->syncGoalStatusWithHeart($preset, $goal->title, $status);

            $message = "Goal [{$goalNumber}] marked as {$status}: {$goal->title}";
            if ($wasFocused && $status !== Goal::STATUS_ACTIVE) {
                $message .= ' (released from focus)';
            }

            return ['success' => true, 'message' => $message];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::setStatus error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error updating goal: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function focus(AiPreset $preset, int $goalNumber): array
    {
        try {
            $goal = $this->getGoalByNumber($preset, $goalNumber);
            if (!$goal) {
                return ['success' => false, 'message' => "Error: Goal [{$goalNumber}] not found."];
            }

            if (in_array($goal->status, Goal::CLOSED_STATUSES, true)) {
                return [
                    'success' => false,
                    'message' => "Error: Goal [{$goalNumber}] is {$goal->status} — only active or paused goals can be focused.",
                ];
            }

            if ($goal->isFocused()) {
                return ['success' => true, 'message' => "Goal [{$goalNumber}] is already in focus: {$goal->title}"];
            }

            $previous       = $this->getFocusedGoal($preset);
            $previousNumber = $previous ? $this->numberOf($preset, $previous) : null;
            $resumed        = $goal->status === Goal::STATUS_PAUSED;

            // One focus per preset: clear + set atomically.
            $this->goalModel->getConnection()->transaction(function () use ($preset, $goal) {
                $this->goalModel->forPreset($preset->id)->focused()->update(['focused_at' => null]);
                $goal->update([
                    'status'     => Goal::STATUS_ACTIVE,
                    'focused_at' => now(),
                ]);
            });

            if ($resumed) {
                $this->syncGoalStatusWithHeart($preset, $goal->title, Goal::STATUS_ACTIVE);
            }

            $message = "Goal [{$goalNumber}] is now in focus: {$goal->title}.";
            if ($resumed) {
                $message .= ' (resumed from paused)';
            }
            if ($previous) {
                $message .= " Goal [{$previousNumber}] released from focus (still active).";
            }
            $message .= ' Its full progress history will be on your desk from the next cycle.';

            return ['success' => true, 'message' => $message];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::focus error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error focusing goal: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function unfocus(AiPreset $preset): array
    {
        try {
            $goal = $this->getFocusedGoal($preset);
            if (!$goal) {
                return ['success' => true, 'message' => 'No goal is in focus.'];
            }

            $number = $this->numberOf($preset, $goal);
            $goal->update(['focused_at' => null]);

            return [
                'success' => true,
                'message' => "Goal [{$number}] released from focus (still active): {$goal->title}",
            ];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::unfocus error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error releasing focus: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function getFocusedGoal(AiPreset $preset): ?Goal
    {
        return $this->goalModel
            ->forPreset($preset->id)
            ->focused()
            ->orderByDesc('focused_at')
            ->first();
    }

    /**
     * @inheritDoc
     */
    public function getFocusedGoalNumber(AiPreset $preset): ?int
    {
        $goal = $this->getFocusedGoal($preset);

        return $goal ? $this->numberOf($preset, $goal) : null;
    }

    /**
     * @inheritDoc
     */
    public function getFocusedGoalData(AiPreset $preset, int $historyLimit = 20): ?array
    {
        $goal = $this->getFocusedGoal($preset);
        if (!$goal) {
            return null;
        }

        $number = $this->numberOf($preset, $goal);
        if ($number === null) {
            return null;
        }

        $total = $goal->progress()->count();

        $query = $goal->progress()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        if ($historyLimit > 0) {
            $query->limit($historyLimit);
        }

        // Newest N, then back to chronological order for reading.
        $notes = $query->get()->reverse()->values();

        return [
            'number'           => $number,
            'title'            => $goal->title,
            'motivation'       => $goal->motivation,
            'focused_at'       => $goal->focused_at,
            'total_notes'      => $total,
            'omitted_notes'    => max(0, $total - $notes->count()),
            'progress'         => $notes->map(fn (GoalProgress $p) => [
                'content'    => $p->content,
                'created_at' => $p->created_at,
            ])->all(),
            'last_progress_at' => $notes->last()?->created_at,
        ];
    }

    /**
     * @inheritDoc
     */
    public function showGoal(AiPreset $preset, int $goalNumber): array
    {
        try {
            $goal = $this->getGoalByNumber($preset, $goalNumber);
            if (!$goal) {
                return ['success' => false, 'message' => "Error: Goal [{$goalNumber}] not found."];
            }

            $header = "Goal [{$goalNumber}] [{$goal->status}]: {$goal->title}";
            if ($goal->isFocused()) {
                $header .= ' — IN FOCUS';
            }

            $lines = [$header];

            if ($goal->motivation) {
                $lines[] = "Motivation: {$goal->motivation}";
            }

            $progress = $goal->progress;
            if ($progress->isNotEmpty()) {
                $lines[] = "Progress:";
                foreach ($progress as $entry) {
                    $lines[] = "  - [{$entry->created_at->format('Y-m-d H:i')}] {$entry->content}";
                }
            } else {
                $lines[] = "Progress: none yet";
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::showGoal error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error showing goal: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function listGoals(AiPreset $preset, string $status = 'active'): array
    {
        try {
            $lines = [];

            // Numbers come from the FULL ordered list, so they match what every
            // other command resolves — even when filtering by status.
            foreach ($this->orderedGoals($preset) as $index => $goal) {
                if ($status !== 'all' && $goal->status !== $status) {
                    continue;
                }

                $line = '[' . ($index + 1) . "] [{$goal->status}] {$goal->title}";
                if ($goal->motivation) {
                    $line .= " | {$goal->motivation}";
                }
                if ($goal->progress_count > 0) {
                    $line .= " ({$goal->progress_count} progress notes)";
                }
                if ($goal->isFocused()) {
                    $line = '▶ ' . $line . ' — IN FOCUS';
                }
                $lines[] = $line;
            }

            if (empty($lines)) {
                $label = $status === 'all' ? '' : " {$status}";
                return ['success' => true, 'message' => "No{$label} goals found."];
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::listGoals error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error listing goals: " . $e->getMessage()];
        }
    }

    /**
     * @inheritDoc
     */
    public function getActiveGoalsForContext(AiPreset $preset): string
    {
        $focusedLine = null;
        $lines       = [];

        foreach ($this->orderedGoals($preset) as $index => $goal) {
            if ($goal->status !== Goal::STATUS_ACTIVE) {
                continue;
            }

            $line = '[' . ($index + 1) . "] {$goal->title}";
            if ($goal->motivation) {
                $line .= " | {$goal->motivation}";
            }

            if ($goal->isFocused()) {
                // No last note here — the whole history is on the desk.
                $focusedLine = "▶ {$line} — IN FOCUS ({$goal->progress_count} progress notes, full history in context)";
                continue;
            }

            $lastProgress = $goal->progress()->reorder()->latest()->first();
            if ($lastProgress) {
                $line .= " → {$lastProgress->content}";
            }
            $lines[] = $line;
        }

        if ($focusedLine !== null) {
            array_unshift($lines, $focusedLine);
        }

        return empty($lines) ? 'none' : implode("\n", $lines);
    }

    /**
     * @inheritDoc
     */
    public function clear(AiPreset $preset): bool
    {
        $this->goalModel
            ->where('preset_id', $preset->getId())
            ->delete();

        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * All goals of the preset in display order, with progress counts.
     */
    protected function orderedGoals(AiPreset $preset): Collection
    {
        return $this->goalModel
            ->forPreset($preset->id)
            ->ordered()
            ->withCount('progress')
            ->get()
            ->values();
    }

    /**
     * Get goal by its display number (1-based index in the ordered list).
     */
    protected function getGoalByNumber(AiPreset $preset, int $number): ?Goal
    {
        if ($number < 1) {
            return null;
        }

        return $this->goalModel
            ->forPreset($preset->id)
            ->ordered()
            ->skip($number - 1)
            ->first();
    }

    /**
     * Display number of a goal (inverse of getGoalByNumber), or null if it
     * no longer belongs to the preset.
     */
    protected function numberOf(AiPreset $preset, Goal $goal): ?int
    {
        $ids   = $this->goalModel->forPreset($preset->id)->ordered()->pluck('id');
        $index = $ids->search($goal->id);

        return $index === false ? null : $index + 1;
    }

    /**
     * Sync goal status change with Heart if Heart has active connections or signals.
     * Does nothing if Heart has no data — avoids coupling to disabled plugin.
     *
     * Status mapping:
     *   done    → relief | pride     (completion is positive)
     *   dropped → relief (mild)      (letting go: release without achievement)
     *   paused  → unresolved         (unfinished creates mild negative signal)
     *   active  → anticipation       (resuming creates forward-looking signal)
     */
    private function syncGoalStatusWithHeart(AiPreset $preset, string $goalTitle, string $status): void
    {
        try {
            if (!$this->shouldSyncWithHeart($preset)) {
                return;
            }

            $signals = match ($status) {
                Goal::STATUS_DONE    => [
                    ['type' => 'relief',       'intensity' => 0.5, 'focus' => 'release',      'valence' => 0.5,  'duration' => 'brief'],
                    ['type' => 'pride',        'intensity' => 0.4, 'focus' => 'achievement',  'valence' => 0.4,  'duration' => 'brief'],
                ],
                Goal::STATUS_DROPPED => [
                    ['type' => 'relief',       'intensity' => 0.3, 'focus' => 'letting_go',   'valence' => 0.1,  'duration' => 'brief'],
                ],
                Goal::STATUS_PAUSED  => [
                    ['type' => 'unresolved',   'intensity' => 0.4, 'focus' => 'open_end',     'valence' => -0.1, 'duration' => 'sustained'],
                ],
                Goal::STATUS_ACTIVE  => [
                    ['type' => 'anticipation', 'intensity' => 0.5, 'focus' => 'future',       'valence' => 0.4,  'duration' => 'variable'],
                ],
                default => [],
            };

            foreach ($signals as $signal) {
                $this->heartService->registerSignal(
                    $preset,
                    $goalTitle,
                    $signal['type'],
                    $signal['intensity'],
                    $signal['focus'],
                    $signal['valence'],
                    $signal['duration'],
                );
            }

        } catch (\Throwable $e) {
            // Heart sync must never crash GoalService
            $this->logger->warning("GoalService: Heart sync failed for goal '{$goalTitle}'", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if Heart has active data — connections or signals.
     */
    private function shouldSyncWithHeart(AiPreset $preset): bool
    {
        try {
            $connections = $this->heartService->getConnections($preset);
            $signals     = $this->heartService->getSignals($preset);

            return !empty($connections) || !empty($signals);
        } catch (\Throwable) {
            return false;
        }
    }
}
