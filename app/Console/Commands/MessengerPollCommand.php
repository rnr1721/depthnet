<?php

namespace App\Console\Commands;

use App\Contracts\Agent\Capabilities\MessengerProviderInterface;
use App\Contracts\Agent\Models\PresetServiceInterface;
use App\Models\PresetCapabilityConfig;
use App\Services\Agent\Capabilities\Messenger\DTO\InboundSettings;
use App\Services\Agent\Capabilities\Messenger\Inbound\InboundPoller;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Deliver incoming messenger messages to agents.
 *
 * Two ways to run it:
 *
 *   Scheduler (simple, up to ~1 min latency):
 *     Schedule::command('messenger:poll')->everyMinute()->withoutOverlapping()->runInBackground();
 *
 *   Supervisor (conversational latency):
 *     php artisan messenger:poll --loop --interval=10
 *
 * Use one or the other. Running both is safe with a lock-capable cache (redis)
 * — the per-preset lock prevents double delivery — but pointless.
 */
class MessengerPollCommand extends Command
{
    protected $signature = 'messenger:poll
        {--preset= : Only poll this preset id}
        {--loop : Keep polling until stopped (for supervisor)}
        {--interval=10 : Seconds between passes with --loop}
        {--reset : Drop the stored cursor(s) and take a fresh baseline (nothing is replayed)}';

    protected $description = 'Fetch new messenger messages and deliver them to agents';

    private bool $stop = false;

    public function handle(InboundPoller $poller, PresetServiceInterface $presets, LoggerInterface $logger): int
    {
        $loop     = (bool) $this->option('loop');
        $interval = max(2, (int) $this->option('interval'));

        if ($loop) {
            $this->trap([SIGTERM, SIGINT], function () {
                $this->stop = true;
            });
        }

        do {
            $started = microtime(true);

            foreach ($this->presetIds() as $presetId) {
                if ($this->stop) {
                    break;
                }

                $preset = $presets->findById($presetId);
                if (!$preset) {
                    continue;
                }

                try {
                    if ($this->option('reset')) {
                        $poller->resetCursor($preset);
                        $this->line("preset #{$presetId}: cursor reset");
                        continue;
                    }

                    $report = $poller->poll($preset);

                    if ($this->output->isVerbose() || $report->status !== 'skipped') {
                        $this->line("preset #{$presetId}: " . $report->summary());
                    }
                } catch (\Throwable $e) {
                    // One preset failing must not stop the others.
                    $logger->error('messenger:poll failed for preset', [
                        'preset_id' => $presetId,
                        'error'     => $e->getMessage(),
                    ]);
                    $this->error("preset #{$presetId}: " . $e->getMessage());
                }
            }

            if ($loop && !$this->stop) {
                $sleep = $interval - (microtime(true) - $started);
                // Sleep in short slices so SIGTERM is honoured promptly.
                for ($t = 0; $t < $sleep && !$this->stop; $t += 0.5) {
                    usleep(500_000);
                }
            }
        } while ($loop && !$this->stop && !$this->option('reset'));

        return self::SUCCESS;
    }

    /**
     * Presets with an active messenger capability whose inbound mode is on.
     * Re-read every pass, so turning inbound on/off needs no restart.
     *
     * @return int[]
     */
    private function presetIds(): array
    {
        $only = $this->option('preset');

        return PresetCapabilityConfig::query()
            ->forCapability(MessengerProviderInterface::CAPABILITY)
            ->active()
            ->when($only, fn ($q) => $q->where('preset_id', (int) $only))
            ->get()
            ->filter(fn ($c) => $this->option('reset')
                || InboundSettings::fromConfig($c->config ?? [])->isEnabled())
            ->pluck('preset_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
