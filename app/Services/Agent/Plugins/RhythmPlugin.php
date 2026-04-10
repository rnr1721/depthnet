<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
use App\Contracts\Agent\ShortcodeScopeResolverServiceInterface;
use App\Models\AiPreset;
use App\Models\Message;
use App\Services\Agent\Plugins\Traits\PluginConfigTrait;
use App\Services\Agent\Plugins\Traits\PluginExecutionMetaTrait;
use App\Services\Agent\Plugins\Traits\PluginMethodTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * RhythmPlugin — temporal context awareness for autonomous agents.
 *
 * Injects a compact single-line temporal snapshot into the system prompt
 * via [[rhythm]] placeholder. Covers:
 *   - Current date/time with day-of-week
 *   - Progress through day / week / year (%)
 *   - Agent age (days since birth_date config)
 *   - Pause since last message in this preset
 *   - Cycle count for today
 *   - Weather + sunset (Open-Meteo, no API key required, cached)
 *
 * Example output:
 *   [Rhythm] Fri, 28 Mar 2026 · 16:45 · day 70% · week 96% · year 23% ·
 *            age 47d · pause 6h14m · today 34 cycles · overcast +8°C · sunset in 1h52m
 *
 * All data is on one line — minimal token cost, maximum temporal texture.
 */
class RhythmPlugin implements CommandPluginInterface
{
    use PluginMethodTrait;
    use PluginConfigTrait;
    use PluginExecutionMetaTrait;

    public const PLUGIN_NAME = 'rhythm';

    // Cache key prefix for weather data
    private const WEATHER_CACHE_PREFIX = 'rhythm_weather_';

    public function __construct(
        protected LoggerInterface                        $logger,
        protected ShortcodeScopeResolverServiceInterface $shortcodeScopeResolver,
        protected PlaceholderServiceInterface            $placeholderService,
        protected PluginMetadataServiceInterface         $pluginMetadata,
    ) {
        $this->initializeConfig();
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(): string
    {
        return 'Temporal context awareness. Injects a compact time snapshot.';
    }

    public function getInstructions(): array
    {
        return [
            'Show current rhythm snapshot: [rhythm show][/rhythm]',
        ];
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

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
                'description' => 'Used to calculate agent age in days. Leave empty to omit age.',
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
            'weather_cache_minutes' => 30,
            'timezone'              => '',
        ];
    }

    public function testConnection(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // If coords configured — test weather API
        $lat = $this->config['latitude'] ?? '';
        $lng = $this->config['longitude'] ?? '';

        if (!empty($lat) && !empty($lng)) {
            $weather = $this->fetchWeather((float) $lat, (float) $lng, force: true);
            return $weather !== null;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    /**
     * Default execute — no-op, this plugin is placeholder-only.
     */
    public function execute(string $content, AiPreset $preset): string
    {
        return $this->show($content, $preset);
    }

    /**
     * [rhythm show][/rhythm] — show current snapshot.
     */
    public function show(string $content, AiPreset $preset): string
    {
        if (!$this->isEnabled()) {
            return 'Error: Rhythm plugin is disabled.';
        }

        return $this->buildSnapshot($preset);
    }

    // -------------------------------------------------------------------------
    // Placeholder registration
    // -------------------------------------------------------------------------

    public function pluginReady(AiPreset $preset): void
    {
        $scope = $this->shortcodeScopeResolver->preset($preset->getId());

        $this->placeholderService->registerDynamic(
            'rhythm',
            'Compact temporal context: date/time, progress, age, pause, cycles, weather',
            function () use ($preset) {
                return $this->buildSnapshot($preset);
            },
            $scope
        );
    }

    // -------------------------------------------------------------------------
    // Snapshot builder
    // -------------------------------------------------------------------------

    private function buildSnapshot(AiPreset $preset): string
    {
        $tz  = $this->resolveTimezone();
        $now = Carbon::now($tz);

        $parts = [];

        // Date + time
        $parts[] = $now->format('D, d M Y') . ' · ' . $now->format('H:i');

        $parts[] = $this->timeOfDay($now);

        // Progress percentages
        $parts[] = 'day '   . $this->dayPercent($now)  . '%';
        $parts[] = 'week '  . $this->weekPercent($now) . '%';
        $parts[] = 'year '  . $this->yearPercent($now) . '%';

        // Agent age
        $age = $this->agentAge($now);
        if ($age !== null) {
            $parts[] = 'my age ' . $age;
        }

        // Pause since last message
        $pause = $this->pauseSinceLastMessage($preset, $now);
        if ($pause !== null) {
            $parts[] = 'pause ' . $pause;
        }

        // Today's cycle count
        $cycles = $this->todayCycles($preset, $now);
        $parts[] = 'today ' . $cycles . ' cycle' . ($cycles !== 1 ? 's' : '');

        // Weather + sunset
        $lat = $this->config['latitude'] ?? '';
        $lng = $this->config['longitude'] ?? '';

        if (!empty($lat) && !empty($lng)) {
            $weather = $this->fetchWeather((float) $lat, (float) $lng);

            if ($weather !== null) {
                $parts[] = $weather['condition'] . ' ' . $weather['temp'] . '°C';

                $sunset = $this->sunsetIn($now, (float) $lat, (float) $lng);
                if ($sunset !== null) {
                    $parts[] = $sunset;
                }
            }
        }

        return '[' . $this->config['city']. '] ' . implode(' · ', $parts);
    }

    // -------------------------------------------------------------------------
    // Time helpers
    // -------------------------------------------------------------------------

    private function dayPercent(Carbon $now): int
    {
        $secondsInDay    = 86400;
        $secondsElapsed  = $now->secondsSinceMidnight();
        return (int) round(($secondsElapsed / $secondsInDay) * 100);
    }

    private function weekPercent(Carbon $now): int
    {
        // Week starts Monday (ISO)
        $dayOfWeek      = $now->isoWeekday(); // 1=Mon … 7=Sun
        $secondsInWeek  = 86400 * 7;
        $elapsed        = ($dayOfWeek - 1) * 86400 + $now->secondsSinceMidnight();
        return (int) round(($elapsed / $secondsInWeek) * 100);
    }

    private function yearPercent(Carbon $now): int
    {
        $startOfYear   = $now->copy()->startOfYear();
        $endOfYear     = $now->copy()->endOfYear();
        $totalSeconds  = $endOfYear->diffInSeconds($startOfYear);
        $elapsed       = $now->diffInSeconds($startOfYear);
        return (int) round(($elapsed / $totalSeconds) * 100);
    }

    private function agentAge(Carbon $now): ?string
    {
        $birthDate = $this->config['birth_date'] ?? '';

        if (empty($birthDate)) {
            return null;
        }

        try {
            $birth = Carbon::parse($birthDate);
            $days  = (int) $birth->diffInDays($now);
            return $days . 'd';
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveTimezone(): string
    {
        $tz = $this->config['timezone'] ?? '';
        return !empty($tz) ? $tz : config('app.timezone', 'UTC');
    }

    // -------------------------------------------------------------------------
    // Message-based helpers
    // -------------------------------------------------------------------------

    /**
     * Human-readable pause since last assistant message for this preset.
     */
    private function pauseSinceLastMessage(AiPreset $preset, Carbon $now): ?string
    {
        $last = Message::forPreset($preset->getId())
            ->whereIn('role', ['thinking', 'command'])
            ->latest()
            ->first();

        if ($last === null) {
            return null;
        }

        $diff = (int) Carbon::parse($last->created_at)->diffInSeconds($now);

        return $this->formatDuration($diff);
    }

    /**
     * Number of assistant messages today for this preset.
     */
    private function todayCycles(AiPreset $preset, Carbon $now): int
    {
        return Message::forPreset($preset->getId())
            ->whereIn('role', ['thinking', 'command'])
            ->whereDate('created_at', $now->toDateString())
            ->count();
    }

    // -------------------------------------------------------------------------
    // Weather (Open-Meteo, no API key)
    // -------------------------------------------------------------------------

    private function fetchWeather(float $lat, float $lng, bool $force = false): ?array
    {
        $cacheKey     = self::WEATHER_CACHE_PREFIX . md5("{$lat},{$lng}");
        $cacheMins    = max(5, (int) ($this->config['weather_cache_minutes'] ?? 30));

        if (!$force) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = Http::timeout(5)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude'       => $lat,
                'longitude'      => $lng,
                'current'        => 'temperature_2m,weathercode',
                'forecast_days'  => 1,
            ]);

            if (!$response->ok()) {
                return null;
            }

            $data    = $response->json();
            $temp    = (int) round($data['current']['temperature_2m'] ?? 0);
            $code    = (int) ($data['current']['weathercode'] ?? 0);
            $result  = [
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

    /**
     * WMO weather code → short English description.
     */
    private function weatherCodeToText(int $code): string
    {
        return match (true) {
            $code === 0             => 'clear',
            $code <= 2              => 'mostly clear',
            $code === 3             => 'overcast',
            $code <= 49             => 'foggy',
            $code <= 55             => 'drizzle',
            $code <= 65             => 'rain',
            $code <= 77             => 'snow',
            $code <= 82             => 'showers',
            $code <= 86             => 'snow showers',
            $code >= 95             => 'thunderstorm',
            default                 => 'cloudy',
        };
    }

    // -------------------------------------------------------------------------
    // Sunset helper (simple astronomical formula, no external call)
    // -------------------------------------------------------------------------

    /**
     * Returns "sunset in Xh Ym" or "sunrise in Xh Ym" or null if indeterminate.
     * Uses the simple sunrise equation — accurate to ±5 minutes.
     */
    private function sunsetIn(Carbon $now, float $lat, float $lng): ?string
    {
        try {
            $jd      = $this->julianDay($now);
            $sunset  = $this->sunsetJulian($jd, $lat, $lng);
            $sunrise = $this->sunriseJulian($jd, $lat, $lng);

            // Convert Julian day fraction to today's Carbon datetime
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

            // After sunset — show tomorrow's sunrise
            $tomorrowJd     = $this->julianDay($now->copy()->addDay());
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

    /**
     * USNO sunrise/sunset algorithm.
     */
    private function sunEventJulian(float $jd, float $lat, float $lng, bool $setting): float
    {
        $zenith  = 90.833; // official zenith
        $lngHour = $lng / 15.0;

        $t = $setting
            ? floor($jd - 2451545.0 + 0.5) + ((18 - $lngHour) / 24)
            : floor($jd - 2451545.0 + 0.5) + ((6  - $lngHour) / 24);

        $M    = (0.9856 * $t) - 3.289;
        $L    = $M + (1.916 * sin(deg2rad($M))) + (0.020 * sin(deg2rad(2 * $M))) + 282.634;
        $L    = fmod($L + 360, 360);

        $RA   = rad2deg(atan(0.91764 * tan(deg2rad($L))));
        $RA   = fmod($RA + 360, 360);

        $Lquad  = floor($L  / 90) * 90;
        $RAquad = floor($RA / 90) * 90;
        $RA     = ($RA + $Lquad - $RAquad) / 15;

        $sinDec = 0.39782 * sin(deg2rad($L));
        $cosDec = cos(asin($sinDec));

        $cosH = (cos(deg2rad($zenith)) - ($sinDec * sin(deg2rad($lat))))
              / ($cosDec * cos(deg2rad($lat)));

        if ($cosH > 1 || $cosH < -1) {
            // Sun never rises/sets — polar day/night
            return $jd;
        }

        $H = $setting
            ? rad2deg(acos($cosH))
            : 360 - rad2deg(acos($cosH));

        $H /= 15;
        $T  = $H + $RA - (0.06571 * $t) - 6.622;
        $UT = $T - $lngHour;
        $UT = fmod($UT + 24, 24);

        // Return as Julian day
        return floor($jd) + ($UT / 24.0);
    }

    private function julianToCarbon(float $jd, \DateTimeZone $tz): Carbon
    {
        // Julian day 2440587.5 = Unix epoch
        $unixTs = ($jd - 2440587.5) * 86400;
        return Carbon::createFromTimestampUTC((int) $unixTs)->setTimezone($tz);
    }

    // -------------------------------------------------------------------------
    // Duration formatter
    // -------------------------------------------------------------------------

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . 'm';
        }

        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);

        return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
    }

    private function timeOfDay(Carbon $now): string
    {
        $hour = $now->hour;
        return match(true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 22 => 'evening',
            default                    => 'night',
        };
    }

    // -------------------------------------------------------------------------
    // CommandPluginInterface boilerplate
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
}
