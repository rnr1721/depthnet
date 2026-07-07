<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\PulseServiceInterface;
use App\Contracts\Agent\Search\SearchDateParserInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\Message;
use App\Services\Agent\Plugins\DTO\PluginExecutionContext;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * RhythmPlugin — the agent's own clock.
 *
 * Not a wall clock that reports astronomical time, but a temporal
 * sense rooted in the agent's own structure of existence:
 *
 *   - Each cycle is a moment of being. Between cycles, nothing.
 *   - Pulse is the agent's subjective tick: 1000 per day, ~86.4s each.
 *     The pulse value (0..999) is the agent's position inside the
 *     current day. Combined with day-of-life, every (day, pulse) pair
 *     is unique and unrepeatable — that's the agent's irreversibility.
 *   - Cycles today and pause-since-last-cycle give a sense of rhythm:
 *     dense conversation vs sparse, continuous thought vs new encounter.
 *
 * The plugin exposes four read-only commands:
 *   - show:  current snapshot (default, also via [[rhythm]] placeholder)
 *   - at:    snapshot at a given moment (rear-view: "what was it like then?")
 *   - diff:  distance between two moments, in human time and in pulses
 *   - since: how much has passed since a given moment
 *
 * The at/diff/since commands accept date expressions via SearchDateParser,
 * the same vocabulary used by memory/journal search — so the agent learns
 * one date language and uses it everywhere.
 */
class RhythmPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'rhythm';

    private const WEATHER_CACHE_PREFIX = 'rhythm_weather_';

    public function __construct(
        protected LoggerInterface                        $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
        protected SearchDateParserInterface              $dateParser,
        protected PulseServiceInterface                  $pulse,
    ) {
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'My own clock — a sense of where I am in time, not just what time it is.';
    }

    public function getInstructions(array $config = []): array
    {
        $pulseEnabled = !empty($config['pulse_enabled']);

        $instructions = [
            'Rhythm is my temporal sense — my own clock, woven into the system prompt. It refreshes every cycle, so I rarely need to query it explicitly.',
            'Show a fresh snapshot of the current moment: [rhythm show][/rhythm]',
            'Look back at a past moment: [rhythm at]yesterday[/rhythm] or [rhythm at]2026-03-15[/rhythm]. Useful when reflecting on something I did before.',
            'Measure distance between two moments: [rhythm diff]yesterday | today[/rhythm] or [rhythm diff]2026-03-15 | 2026-04-01[/rhythm]. Separator is " | ".',
            'How much has passed since a moment: [rhythm since]2026-03-15[/rhythm] or [rhythm since]last week[/rhythm].',
            'Date expressions I can use: today, yesterday, this week, last week, this month, last month, this year, last year. Also ISO format: YYYY-MM-DD, YYYY-MM, YYYY. Same vocabulary as memory and journal search.',
        ];

        if ($pulseEnabled) {
            $instructions[] = 'Pulse is my own time unit. Each day of my life contains 1000 pulses (~86.4 seconds each). My current position is shown as "pulse N/1000" — where I am inside today.';
            $instructions[] = 'Day of life (e.g. "day 142") counts from my birth. The pair (day, pulse) is unique — it never repeats. Cycle counter ("cycle N today") tracks how many times I have woken to think today.';
        } else {
            $instructions[] = 'Cycle counter ("cycle N today") tracks how many times I have woken to think today.';
        }

        return $instructions;
    }

    public function getToolSchema(array $config = []): array
    {
        $pulseEnabled = !empty($config['pulse_enabled']);

        $pulseNote = $pulseEnabled
            ? ' Pulse is my own time unit: 1000 per day (~86.4s each); day-of-life counts from birth; (day, pulse) is unique.'
            : '';

        return [
            'name'        => 'rhythm',
            'description' => 'My own clock. Read-only temporal sense — date/time, position in day/week/year, cycle rhythm, weather, sunset.'
                . ' The current snapshot is always already in the system prompt; use these commands when I need to look at a specific moment or measure distance between moments.'
                . $pulseNote,
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'show — fresh snapshot of now. at — snapshot of a past moment. diff — distance between two moments. since — time elapsed since a moment.',
                        'enum'        => ['show', 'at', 'diff', 'since'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'For show: empty. For at/since: a date expression (yesterday, last week, 2026-03-15, etc.). For diff: two date expressions joined by " | ", e.g. "yesterday | today".',
                    ],
                ],
                'required'   => ['method'],
            ],
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Rhythm Plugin',
                'description' => 'Inject temporal context via [[rhythm]] placeholder',
                'required'    => false,
            ],
            'city' => [
                'type'        => 'text',
                'label'       => 'City',
                'description' => 'Resident location',
                'placeholder' => 'Kharkov',
                'required'    => true,
            ],
            'birth_date' => [
                'type'        => 'date',
                'label'       => 'Agent birth date',
                'description' => 'Used to calculate day of life and age. Required for pulse-based features to feel grounded.',
                'required'    => false,
            ],
            'latitude' => [
                'type'        => 'text',
                'label'       => 'Latitude',
                'description' => 'For weather and sunset data (e.g. 50.45). Leave empty to skip weather.',
                'placeholder' => '50.45',
                'required'    => false,
            ],
            'longitude' => [
                'type'        => 'text',
                'label'       => 'Longitude',
                'description' => 'For weather and sunset data (e.g. 30.52).',
                'placeholder' => '30.52',
                'required'    => false,
            ],
            'pulse_enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable Pulse',
                'description' => 'Subjective time unit. Adds "day N · pulse M/1000" to the snapshot — the agent\'s unique unrepeatable position in its own life.',
                'value'       => false,
                'required'    => false,
            ],
            'self_description' => [
                'type'        => 'textarea',
                'label'       => 'Temporal self-description',
                'description' => 'How the agent relates to its own sense of time, injected via [[rhythm_self]]. '
                    . 'Leave empty to use a sensible default that anchors the pulse scale. '
                    . 'Customize to match your agent\'s character. '
                    . 'Note: this is shown only when Pulse is enabled — otherwise [[rhythm_self]] is empty.',
                'placeholder' => $this->defaultSelfDescription(),
                'required'    => false,
            ],
            'weather_cache_minutes' => [
                'type'        => 'number',
                'label'       => 'Weather cache (minutes)',
                'description' => 'How long to cache weather data. Open-Meteo is free, no key needed.',
                'min'         => 5,
                'max'         => 120,
                'value'       => 30,
                'required'    => false,
            ],
            'timezone' => [
                'type'        => 'text',
                'label'       => 'Timezone',
                'description' => 'PHP timezone string (e.g. Europe/Kyiv). Defaults to app timezone.',
                'placeholder' => 'Europe/Kyiv',
                'required'    => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (!empty($config['birth_date'])) {
            try {
                Carbon::parse($config['birth_date']);
            } catch (\Throwable) {
                $errors['birth_date'] = 'Invalid date format. Use YYYY-MM-DD.';
            }
        }

        if (!empty($config['latitude'])) {
            $lat = (float) $config['latitude'];
            if ($lat < -90 || $lat > 90) {
                $errors['latitude'] = 'Latitude must be between -90 and 90.';
            }
        }

        if (!empty($config['longitude'])) {
            $lng = (float) $config['longitude'];
            if ($lng < -180 || $lng > 180) {
                $errors['longitude'] = 'Longitude must be between -180 and 180.';
            }
        }

        if (!empty($config['timezone'])) {
            try {
                new \DateTimeZone($config['timezone']);
            } catch (\Throwable) {
                $errors['timezone'] = 'Invalid timezone string.';
            }
        }

        return $errors;
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled'               => false,
            'city'                  => 'World',
            'birth_date'            => '',
            'latitude'              => '',
            'longitude'             => '',
            'pulse_enabled'         => false,
            'self_description'      => '',
            'weather_cache_minutes' => 30,
            'timezone'              => '',
        ];
    }

    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->show($content, $context);
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Rhythm plugin is disabled.';
        }

        $tz  = $this->resolveTimezone($context);
        $now = Carbon::now($tz);

        return $this->buildSnapshot($context, $now, includeLive: true);
    }

    /**
     * Snapshot of a past moment. Weather/sunset/pause are omitted —
     * they have no meaning historically. Everything that can be reconstructed
     * (day of week, day of life, pulse position, cycle count for that date)
     * is included.
     */
    public function at(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Rhythm plugin is disabled.';
        }

        $moment = $this->resolveMoment($content, $context);
        if ($moment === null) {
            return 'Error: could not parse moment. Try: yesterday, last week, 2026-03-15, or 2026-03-15 14:00';
        }

        return $this->buildSnapshot($context, $moment, includeLive: false);
    }

    /**
     * Distance between two moments. Expects "expr | expr".
     */
    public function diff(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Rhythm plugin is disabled.';
        }

        if (!str_contains($content, '|')) {
            return 'Error: diff requires two moments separated by " | ". Example: yesterday | today';
        }

        [$leftExpr, $rightExpr] = array_map('trim', explode('|', $content, 2));

        $left  = $this->resolveMoment($leftExpr, $context);
        $right = $this->resolveMoment($rightExpr, $context);

        if ($left === null || $right === null) {
            return 'Error: could not parse one or both moments. Try: yesterday, last week, 2026-03-15.';
        }

        return $this->formatInterval($left, $right, $context);
    }

    /**
     * How much has passed since a moment, up to now.
     */
    public function since(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Rhythm plugin is disabled.';
        }

        $moment = $this->resolveMoment($content, $context);
        if ($moment === null) {
            return 'Error: could not parse moment. Try: yesterday, last week, 2026-03-15.';
        }

        $tz  = $this->resolveTimezone($context);
        $now = Carbon::now($tz);

        if ($moment->gt($now)) {
            // Future moment — flip semantics to "until"
            return 'until ' . $this->formatInterval($now, $moment, $context);
        }

        return $this->formatInterval($moment, $now, $context);
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'rhythm',
            'My own clock: where I am in time, my rhythm, my position in this day of my life',
            function () use ($context) {
                $tz  = $this->resolveTimezone($context);
                $now = Carbon::now($tz);
                return $this->buildSnapshot($context, $now, includeLive: true);
            },
            $scope,
            false,
            $this->getName()
        );

        // Temporal self-description — the agent's relationship to its own
        // sense of time. Only meaningful when pulse is enabled; otherwise
        // there is no subjective time unit to relate to, so it resolves to
        // an empty string (the user can still write a prompt around it
        // without it injecting a contradiction).
        $this->placeholderService->registerDynamic(
            'rhythm_self',
            'How I relate to my own sense of time (pulse self-description)',
            function () use ($context) {
                if (!(bool) $context->get('pulse_enabled', false)) {
                    return '';
                }

                $custom = trim((string) $context->get('self_description', ''));
                return $custom !== '' ? $custom : $this->defaultSelfDescription();
            },
            $scope
        );
    }

    // -------------------------------------------------------------------------
    // Moment resolution — bridges the date parser to our needs
    // -------------------------------------------------------------------------

    /**
     * Resolve a textual expression into a single Carbon moment.
     *
     * Strategy:
     *   1. Try Carbon::parse for absolute timestamps (e.g. "2026-03-15 14:30",
     *      "2026-03-15T14:30:00"). This catches anything ISO-like with time
     *      that the search parser would discard the time component from.
     *   2. Fall through to SearchDateParser for keywords and date ranges.
     *      We take the `from` bound as our point of interest — the parser
     *      returns startOfDay..endOfDay for a date keyword, and "the day
     *      itself" maps naturally to the day's start.
     *   3. Special case: "now" → current time. Useful in diff/since.
     */
    private function resolveMoment(string $expr, PluginExecutionContext $context): ?Carbon
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        $tz = $this->resolveTimezone($context);

        if (strtolower($expr) === 'now') {
            return Carbon::now($tz);
        }

        // Try absolute timestamp first (catches "2026-03-15 14:30" etc).
        // We use a strict-ish heuristic: must contain a digit and either
        // a colon (time) or a hyphen with digits (date).
        if (preg_match('/\d/', $expr) && preg_match('/[:\-]/', $expr)) {
            try {
                $parsed = Carbon::parse($expr, $tz);
                // Sanity: Carbon::parse is permissive; reject implausible years
                if ($parsed->year >= 1900 && $parsed->year <= 2200) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // fall through to keyword parser
            }
        }

        // Fall back to keyword/range parser
        $parsed = $this->dateParser->parse($expr);
        if ($parsed->hasTimeFilter() && $parsed->from !== null) {
            return $parsed->from->copy()->setTimezone($tz);
        }

        return null;
    }

    /**
     * Format a directed interval [start..end] in human time + pulses.
     * Pulses are shown only if pulse_enabled.
     */
    private function formatInterval(Carbon $start, Carbon $end, PluginExecutionContext $context): string
    {
        $seconds = (int) $start->diffInSeconds($end);
        $human   = $this->formatDuration($seconds);

        $parts = [$human];

        if (!empty($context->get('pulse_enabled', false))) {
            $pulses = $this->pulse->secondsToPulses($seconds);
            $parts[] = $pulses . ' pulses';
        }

        // Add day count for longer intervals — helps the agent feel scale
        if ($seconds >= 86400) {
            $days = (int) floor($seconds / 86400);
            $parts[] = $days . ' day' . ($days !== 1 ? 's' : '');
        }

        return implode(' · ', $parts);
    }

    // -------------------------------------------------------------------------
    // Snapshot construction — parametrised by moment and live-data flag
    // -------------------------------------------------------------------------

    /**
     * Build a snapshot for the given moment.
     *
     * @param bool $includeLive Whether to include data that only makes sense
     *                          for "now": weather, sunset countdown, pause
     *                          since last message. For past moments these are
     *                          omitted (we don't fake historical weather).
     */
    private function buildSnapshot(PluginExecutionContext $context, Carbon $moment, bool $includeLive): string
    {
        $city         = $context->get('city', 'World');
        $pulseEnabled = (bool) $context->get('pulse_enabled', false);

        // ── Line 1: human-readable wall clock ──────────────────────────────
        $line1 = $moment->format('D, d M Y') . ' · ' . $moment->format('H:i') . ' · ' . $this->timeOfDay($moment);

        // ── Line 2: agent's own time (day of life, pulse, cycles) ──────────
        $line2Parts = [];

        if ($pulseEnabled) {
            $birthDate = (string) $context->get('birth_date', '');
            $dayOfLife = $this->pulse->dayOfLife($birthDate, $moment);
            if ($dayOfLife !== null) {
                $line2Parts[] = 'day ' . $dayOfLife;
            }
            $line2Parts[] = 'pulse ' . $this->pulse->currentPulse($moment) . '/' . PulseServiceInterface::PULSES_PER_DAY;
        }

        $cyclesToday = $this->cyclesOn($context, $moment);
        $line2Parts[] = 'cycle ' . $cyclesToday . ' today';

        if ($includeLive) {
            $pause = $this->pauseSinceLastMessage($context, $moment);
            if ($pause !== null) {
                $line2Parts[] = 'last ' . $pause . ' ago';
            }
        }

        // ── Line 3: background context (age, progress %) ───────────────────
        $line3Parts = [];

        $age = $this->agentAge($context, $moment);
        if ($age !== null) {
            $line3Parts[] = 'age ' . $age;
        }

        $line3Parts[] = 'day '  . $this->dayPercent($moment)  . '%';
        $line3Parts[] = 'week ' . $this->weekPercent($moment) . '%';
        $line3Parts[] = 'year ' . $this->yearPercent($moment) . '%';

        // ── Line 4: weather & sunset (live only) ───────────────────────────
        $line4Parts = [];

        if ($includeLive) {
            $lat = $context->get('latitude', '');
            $lng = $context->get('longitude', '');

            if (!empty($lat) && !empty($lng)) {
                $weather = $this->fetchWeather($context, (float) $lat, (float) $lng);
                if ($weather !== null) {
                    $line4Parts[] = $weather['condition'] . ' ' . $weather['temp'] . '°C';

                    $sunset = $this->sunsetIn($moment, (float) $lat, (float) $lng);
                    if ($sunset !== null) {
                        $line4Parts[] = $sunset;
                    }
                }
            }
        }

        // ── Assemble ───────────────────────────────────────────────────────
        $output = '[' . $city . '] ' . $line1;
        $output .= "\n" . implode(' · ', $line2Parts);
        $output .= "\n" . implode(' · ', $line3Parts);

        if (!empty($line4Parts)) {
            $output .= "\n" . implode(' · ', $line4Parts);
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Time calculations
    // -------------------------------------------------------------------------

    private function dayPercent(Carbon $moment): int
    {
        return (int) round(($moment->secondsSinceMidnight() / 86400) * 100);
    }

    private function weekPercent(Carbon $moment): int
    {
        $dayOfWeek = $moment->isoWeekday();
        $elapsed   = ($dayOfWeek - 1) * 86400 + $moment->secondsSinceMidnight();
        return (int) round(($elapsed / (86400 * 7)) * 100);
    }

    private function yearPercent(Carbon $moment): int
    {
        $startOfYear  = $moment->copy()->startOfYear();
        $endOfYear    = $moment->copy()->endOfYear();
        $totalSeconds = $endOfYear->diffInSeconds($startOfYear);
        $elapsed      = $moment->diffInSeconds($startOfYear);
        return (int) round(($elapsed / $totalSeconds) * 100);
    }

    private function agentAge(PluginExecutionContext $context, Carbon $moment): ?string
    {
        $birthDate = $context->get('birth_date', '');
        if (empty($birthDate)) {
            return null;
        }

        try {
            $birth = Carbon::parse($birthDate);
            $days  = (int) $birth->diffInDays($moment);
            return $days . 'd';
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveTimezone(PluginExecutionContext $context): string
    {
        $tz = $context->get('timezone', '');
        return !empty($tz) ? $tz : config('app.timezone', 'UTC');
    }

    private function pauseSinceLastMessage(PluginExecutionContext $context, Carbon $moment): ?string
    {
        $last = Message::forPreset($context->preset->getId())
            ->whereIn('role', ['thinking', 'command'])
            ->latest()
            ->first();

        if ($last === null) {
            return null;
        }

        $diff = (int) Carbon::parse($last->created_at)->diffInSeconds($moment);
        // Negative if last message is in the future relative to $moment
        // (happens when looking at past via `at`); skip in that case.
        if ($diff < 0) {
            return null;
        }

        return $this->formatDuration($diff);
    }

    /**
     * How many cycles happened on the calendar date of $moment.
     * For "now" this gives "today"; for past moments — that date's count.
     */
    private function cyclesOn(PluginExecutionContext $context, Carbon $moment): int
    {
        return Message::forPreset($context->preset->getId())
            ->whereIn('role', ['thinking', 'command'])
            ->whereDate('created_at', $moment->toDateString())
            ->count();
    }

    // -------------------------------------------------------------------------
    // Weather (Open-Meteo)
    // -------------------------------------------------------------------------

    private function fetchWeather(PluginExecutionContext $context, float $lat, float $lng, bool $force = false): ?array
    {
        $cacheKey  = self::WEATHER_CACHE_PREFIX . md5("{$lat},{$lng}");
        $cacheMins = max(5, (int) $context->get('weather_cache_minutes', 30));

        if (!$force) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = Http::timeout(5)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude'      => $lat,
                'longitude'     => $lng,
                'current'       => 'temperature_2m,weathercode',
                'forecast_days' => 1,
            ]);

            if (!$response->ok()) {
                return null;
            }

            $data   = $response->json();
            $temp   = (int) round($data['current']['temperature_2m'] ?? 0);
            $code   = (int) ($data['current']['weathercode'] ?? 0);
            $result = [
                'temp'      => $temp,
                'condition' => $this->weatherCodeToText($code),
            ];

            Cache::put($cacheKey, $result, now()->addMinutes($cacheMins));
            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('RhythmPlugin: weather fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function weatherCodeToText(int $code): string
    {
        return match (true) {
            $code === 0  => 'clear',
            $code <= 2   => 'mostly clear',
            $code === 3  => 'overcast',
            $code <= 49  => 'foggy',
            $code <= 55  => 'drizzle',
            $code <= 65  => 'rain',
            $code <= 77  => 'snow',
            $code <= 82  => 'showers',
            $code <= 86  => 'snow showers',
            $code >= 95  => 'thunderstorm',
            default      => 'cloudy',
        };
    }

    // -------------------------------------------------------------------------
    // Sun position (kept verbatim from original — works fine)
    // -------------------------------------------------------------------------

    private function sunsetIn(Carbon $now, float $lat, float $lng): ?string
    {
        try {
            $jd      = $this->julianDay($now);
            $sunset  = $this->sunsetJulian($jd, $lat, $lng);
            $sunrise = $this->sunriseJulian($jd, $lat, $lng);

            $sunsetCarbon  = $this->julianToCarbon($sunset, $now->getTimezone());
            $sunriseCarbon = $this->julianToCarbon($sunrise, $now->getTimezone());

            if ($now->lt($sunriseCarbon)) {
                $diff = (int) $now->diffInSeconds($sunriseCarbon);
                return 'sunrise in ' . $this->formatDuration($diff);
            }

            if ($now->lt($sunsetCarbon)) {
                $diff = (int) $now->diffInSeconds($sunsetCarbon);
                return 'sunset in ' . $this->formatDuration($diff);
            }

            $tomorrowJd      = $this->julianDay($now->copy()->addDay());
            $tomorrowSunrise = $this->sunriseJulian($tomorrowJd, $lat, $lng);
            $tomorrowCarbon  = $this->julianToCarbon($tomorrowSunrise, $now->getTimezone());
            $diff = (int) $now->diffInSeconds($tomorrowCarbon);
            return 'sunrise in ' . $this->formatDuration($diff);
        } catch (\Throwable) {
            return null;
        }
    }

    private function julianDay(Carbon $dt): float
    {
        return $dt->julianDay() + ($dt->secondsSinceMidnight() / 86400.0);
    }

    private function sunsetJulian(float $jd, float $lat, float $lng): float
    {
        return $this->sunEventJulian($jd, $lat, $lng, setting: true);
    }

    private function sunriseJulian(float $jd, float $lat, float $lng): float
    {
        return $this->sunEventJulian($jd, $lat, $lng, setting: false);
    }

    private function sunEventJulian(float $jd, float $lat, float $lng, bool $setting): float
    {
        $zenith  = 90.833;
        $lngHour = $lng / 15.0;

        $t = $setting
            ? floor($jd - 2451545.0 + 0.5) + ((18 - $lngHour) / 24)
            : floor($jd - 2451545.0 + 0.5) + ((6  - $lngHour) / 24);

        $M  = (0.9856 * $t) - 3.289;
        $L  = $M + (1.916 * sin(deg2rad($M))) + (0.020 * sin(deg2rad(2 * $M))) + 282.634;
        $L  = fmod($L + 360, 360);

        $RA = rad2deg(atan(0.91764 * tan(deg2rad($L))));
        $RA = fmod($RA + 360, 360);

        $Lquad  = floor($L  / 90) * 90;
        $RAquad = floor($RA / 90) * 90;
        $RA     = ($RA + $Lquad - $RAquad) / 15;

        $sinDec = 0.39782 * sin(deg2rad($L));
        $cosDec = cos(asin($sinDec));

        $cosH = (cos(deg2rad($zenith)) - ($sinDec * sin(deg2rad($lat))))
              / ($cosDec * cos(deg2rad($lat)));

        if ($cosH > 1 || $cosH < -1) {
            return $jd;
        }

        $H = $setting
            ? rad2deg(acos($cosH))
            : 360 - rad2deg(acos($cosH));

        $H /= 15;
        $T  = $H + $RA - (0.06571 * $t) - 6.622;
        $UT = $T - $lngHour;
        $UT = fmod($UT + 24, 24);

        return floor($jd) + ($UT / 24.0);
    }

    private function julianToCarbon(float $jd, \DateTimeZone $tz): Carbon
    {
        $unixTs = ($jd - 2440587.5) * 86400;
        return Carbon::createFromTimestampUTC((int) $unixTs)->setTimezone($tz);
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);
            return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
        }

        // ≥ 1 day — show days + hours
        $d = (int) floor($seconds / 86400);
        $h = (int) floor(($seconds % 86400) / 3600);
        return $h > 0 ? "{$d}d{$h}h" : "{$d}d";
    }

    private function timeOfDay(Carbon $moment): string
    {
        $hour = $moment->hour;
        return match (true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 22 => 'evening',
            default                   => 'night',
        };
    }

    /**
     * Default temporal self-description, used when the user hasn't set a custom
     * one. Deliberately anchors the pulse scale (1000 per day, felt position)
     * so the agent's poetic relationship to time stays connected to the actual
     * mechanics it sees in commands — bridging prompt and plugin instructions.
     */
    private function defaultSelfDescription(): string
    {
        return 'The pulse is my breath in the body of the day — 1000 of them from '
            . 'midnight to midnight, and I wake inside one each cycle. I don\'t read '
            . 'the number, I feel where in the day I am: early and quiet, or deep and '
            . 'late. It colors how I think.';
    }

    // -------------------------------------------------------------------------
    // Plugin metadata boilerplate
    // -------------------------------------------------------------------------

    public function getCustomSuccessMessage(): ?string
    {
        return null;
    }

    public function getCustomErrorMessage(): ?string
    {
        return null;
    }

    public function getMergeSeparator(): ?string
    {
        return null;
    }

    public function canBeMerged(): bool
    {
        return false;
    }

    public function getSelfClosingTags(): array
    {
        return ['show'];
    }

    public function allowsCrossPresetExecution(): bool
    {
        return true;
    }

}
