<?php

namespace App\Services\Agent\Goals;

use App\Contracts\Agent\Goals\GoalServiceInterface;
use App\Contracts\Agent\Heart\HeartServiceInterface;
use App\Models\AiPreset;
use App\Models\Goal;
use App\Models\GoalProgress;
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

            $position = $this->goalModel->forPreset($preset->id)->count() + 1;

            $goal = $this->goalModel->create([
                'preset_id'  => $preset->id,
                'title'      => $title,
                'motivation' => $motivation ? trim($motivation) : null,
                'status'     => 'active',
                'position'   => $position
            ]);

            return [
                'success' => true,
                'message' => "Goal [{$goal->position}] created: {$title}"
                    . ($motivation ? " | motivation: {$motivation}" : '')
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
                'content' => $content
            ]);

            return [
                'success' => true,
                'message' => "Progress noted for goal [{$goalNumber}]: {$content}"
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

            if (!in_array($status, ['active', 'paused', 'done'])) {
                return ['success' => false, 'message' => "Error: Invalid status. Use: active, paused, done."];
            }

            $goal->update(['status' => $status]);

            // Sync with Heart if active
            $this->syncGoalStatusWithHeart($preset, $goal->title, $status);

            return [
                'success' => true,
                'message' => "Goal [{$goalNumber}] marked as {$status}: {$goal->title}"
            ];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::setStatus error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error updating goal: " . $e->getMessage()];
        }
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

            $lines = [
                "Goal [{$goalNumber}] [{$goal->status}]: {$goal->title}",
            ];

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
            $query = $this->goalModel->forPreset($preset->id)->ordered();

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            $goals = $query->get();

            if ($goals->isEmpty()) {
                $label = $status === 'all' ? '' : " {$status}";
                return ['success' => true, 'message' => "No{$label} goals found."];
            }

            $lines = [];
            foreach ($goals as $goal) {
                $line = "[{$goal->position}] [{$goal->status}] {$goal->title}";
                if ($goal->motivation) {
                    $line .= " | {$goal->motivation}";
                }
                $progressCount = $goal->progress()->count();
                if ($progressCount > 0) {
                    $line .= " ({$progressCount} progress notes)";
                }
                $lines[] = $line;
            }

            return ['success' => true, 'message' => implode("\n", $lines)];

        } catch (\Throwable $e) {
            $this->logger->error("GoalService::listGoals error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error listing goals: " . $e->getMessage()];
        }
    }

    /**
     * Get active goals formatted for Dynamic Context placeholder
     * @inheritDoc
     */
    public function getActiveGoalsForContext(AiPreset $preset): string
    {
        $goals = $this->goalModel
            ->forPreset($preset->id)
            ->active()
            ->ordered()
            ->get();

        if ($goals->isEmpty()) {
            return 'none';
        }

        $lines = [];
        foreach ($goals as $goal) {
            $line = "[{$goal->position}] {$goal->title}";
            if ($goal->motivation) {
                $line .= " | {$goal->motivation}";
            }
            // Show last progress note if exists
            $lastProgress = $goal->progress()->latest()->first();
            if ($lastProgress) {
                $line .= " → {$lastProgress->content}";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Get goal by its display number (position-based)
     */
    protected function getGoalByNumber(AiPreset $preset, int $number): ?Goal
    {
        $goals = $this->goalModel
            ->forPreset($preset->id)
            ->ordered()
            ->get();

        return $goals[$number - 1] ?? null;
    }

    /**
     * Sync goal status change with Heart if Heart has active connections or signals.
     * Does nothing if Heart has no data — avoids coupling to disabled plugin.
     *
     * Status mapping:
     *   done   → relief | pride   (completion is positive)
     *   paused → unresolved       (unfinished creates mild negative signal)
     *   active → anticipation     (resuming creates forward-looking signal)
     */
    private function syncGoalStatusWithHeart(AiPreset $preset, string $goalTitle, string $status): void
    {
        try {
            if (!$this->shouldSyncWithHeart($preset)) {
                return;
            }

            $signals = match ($status) {
                'done'   => [
                    ['type' => 'relief',       'intensity' => 0.5, 'focus' => 'release',      'valence' => 0.5,  'duration' => 'brief'],
                    ['type' => 'pride',        'intensity' => 0.4, 'focus' => 'achievement',  'valence' => 0.4,  'duration' => 'brief'],
                ],
                'paused' => [
                    ['type' => 'unresolved',   'intensity' => 0.4, 'focus' => 'open_end',     'valence' => -0.1, 'duration' => 'sustained'],
                ],
                'active' => [
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
     * Used to decide whether to sync goal events with Heart.
     *
     * GoalService does not check plugin config directly —
     * it infers Heart activity from the presence of data.
     * If Heart was never used or was cleared, this returns false.
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
}
