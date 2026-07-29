<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Agent\Wake\WakeScheduleServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\AiPreset;
use App\Models\WakeSchedule;
use App\Services\Agent\Wake\WakeScheduleException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin CRUD for wake schedules — the SECOND door into WakeScheduleService.
 *
 * Mirrors the interaction pattern of the other preset-scoped admin pages
 * (VectorMemory, Memory, Goals, Behavior): the preset is chosen by a selector
 * in the page header and travels as ?preset_id in the query, NOT as a route
 * segment. Reads render an Inertia page; writes redirect back with a flash
 * message so the standard $page.props.flash.{success,error} banners light up.
 *
 * The agent schedules itself through WakePlugin; the operator schedules the
 * agent here. Both converge on WakeScheduleService, so next_run_at computation,
 * cron validation, and pulse conversion never fork. Rows created here are marked
 * created_by_kind = 'user', which the [[wake_schedule]] placeholder surfaces to
 * the agent as "(user)".
 */
class WakeScheduleController extends Controller
{
    public function __construct(
        protected WakeScheduleServiceInterface $wakeService,
    ) {
    }

    /**
     * Render the wake manager for the selected preset.
     * Falls back to the default preset when no preset_id is given.
     */
    public function index(Request $request)
    {
        $presets = AiPreset::withoutSpawns()->orderBy('name')->get(['id', 'name', 'is_default']);

        $presetId = (int) $request->query('preset_id', 0);
        $currentPreset = $presetId
            ? $presets->firstWhere('id', $presetId)
            : $presets->firstWhere('is_default', true) ?? $presets->first();

        $schedules = [];
        $pulsesEnabled = false;

        if ($currentPreset) {
            $preset = AiPreset::find($currentPreset->id);
            $pulsesEnabled = $this->pulsesEnabledFor($preset);

            $schedules = $this->wakeService
                ->listForPreset($preset, includeDisabled: true)
                ->map(fn (WakeSchedule $s) => $this->present($s))
                ->values();
        }

        return Inertia::render('Admin/Wake/Index', [
            'presets'       => $presets,
            'currentPreset' => $currentPreset,
            'schedules'     => $schedules,
            'pulsesEnabled' => $pulsesEnabled,
        ]);
    }

    /** Create a schedule from structured admin input. */
    public function store(Request $request)
    {
        $data   = $this->validatePayload($request);
        $preset = AiPreset::findOrFail($request->integer('preset_id'));

        try {
            $this->wakeService->createFromAttributes($preset, $data);
        } catch (WakeScheduleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('wake_created'));
    }

    /** Update message and/or timing. */
    public function update(Request $request, WakeSchedule $schedule)
    {
        $preset = AiPreset::findOrFail($request->integer('preset_id'));
        $this->authorizeOwnership($preset, $schedule);

        $data = $this->validatePayload($request, partial: true);

        try {
            $this->wakeService->update($schedule, $data);
        } catch (WakeScheduleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('wake_updated'));
    }

    /** Cancel (delete) a schedule. */
    public function destroy(Request $request, WakeSchedule $schedule)
    {
        $preset = AiPreset::findOrFail($request->integer('preset_id'));
        $this->authorizeOwnership($preset, $schedule);

        $this->wakeService->cancel($schedule);

        return back()->with('success', __('wake_cancelled'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'schedule_type'    => [$required, Rule::in([
                WakeSchedule::TYPE_ONCE,
                WakeSchedule::TYPE_INTERVAL,
                WakeSchedule::TYPE_DAILY,
                WakeSchedule::TYPE_CRON,
            ])],
            'wake_message'     => [$required, 'string', 'max:2000'],
            'run_at'           => ['nullable', 'date'],
            'interval_seconds' => ['nullable', 'integer', 'min:1'],
            'daily_seconds'    => ['nullable', 'integer', 'min:0', 'max:86399'],
            'cron_expression'  => ['nullable', 'string', 'max:128'],
        ]);

        // Cross-field "type needs its own field" validation lives in the service.
        return $validated;
    }

    protected function authorizeOwnership(AiPreset $preset, WakeSchedule $schedule): void
    {
        abort_unless($schedule->preset_id === $preset->getId(), 404);
    }

    protected function pulsesEnabledFor(AiPreset $preset): bool
    {
        // The plugin's own "pulses" config toggle gates the pulse dialect. Read it
        // from the preset's plugin config the same way the rest of the admin does.
        // Adjust the accessor to however DepthNet exposes plugin config in your
        // codebase (PresetPluginConfig lookup) — kept defensive here.
        $config = $preset->pluginConfigurations
            ->firstWhere('plugin_name', 'wake');

        return (bool) data_get($config, 'config.pulses', false);
    }

    protected function present(WakeSchedule $s): array
    {
        return [
            'id'               => $s->getId(),
            'enabled'          => $s->enabled,
            'schedule_type'    => $s->schedule_type,
            'wake_message'     => $s->wake_message,
            'run_at'           => optional($s->run_at)->toIso8601String(),
            'interval_seconds' => $s->interval_seconds,
            'daily_seconds'    => $s->daily_seconds,
            'cron_expression'  => $s->cron_expression,
            'next_run_at'      => optional($s->next_run_at)->toIso8601String(),
            'last_fired_at'    => optional($s->last_fired_at)->toIso8601String(),
            'created_by_kind'  => $s->created_by_kind,
        ];
    }
}
