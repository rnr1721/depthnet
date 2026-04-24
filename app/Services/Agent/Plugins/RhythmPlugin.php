<?php

namespace App\Services\Agent\Plugins;

use App\Contracts\Agent\CommandPluginInterface;
use App\Contracts\Agent\PlaceholderServiceInterface;
use App\Contracts\Agent\Plugins\PluginMetadataServiceInterface;
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
 * RhythmPlugin — stateless temporal context awareness.
 *
 * Injects a compact single-line temporal snapshot into the system prompt
 * via [[rhythm]] placeholder.
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
    ) {
    }

    public function getName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getDescription(array $config = []): string
    {
        return 'Temporal context awareness. Injects a compact time snapshot.';
    }

    public function getInstructions(array $config = []): array
    {
        return [
            'Show current rhythm snapshot: [rhythm show][/rhythm]',
        ];
    }

    public function getToolSchema(array $config = []): array
    {
        return [
            'name'        => 'rhythm',
            'description' => 'Read-only temporal context. Shows current date/time, day/week/year progress, agent age, pause since last message, cycles today, weather and sunset. '
                . 'This data is always available via rhythm placeholder — use show command only when you need a fresh snapshot.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'method' => [
                        'type'        => 'string',
                        'description' => 'Only one operation available.',
                        'enum'        => ['show'],
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'Leave empty.',
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

    public function execute(string $content, PluginExecutionContext $context): string
    {
        return $this->show($content, $context);
    }

    public function show(string $content, PluginExecutionContext $context): string
    {
        if (!$context->enabled) {
            return 'Error: Rhythm plugin is disabled.';
        }

        return $this->buildSnapshot($context);
    }

    public function registerShortcodes(PluginExecutionContext $context): void
    {
        $scope = $this->shortcodeScopeResolver->preset($context->preset->getId());

        $this->placeholderService->registerDynamic(
            'rhythm',
            'Compact temporal context: date/time, progress, age, pause, cycles, weather',
            function () use ($context) {
                return $this->buildSnapshot($context);
            },
            $scope
        );
    }

    // -------------------------------------------------------------------------
    // Snapshot builder — now reads everything from $context
    // -------------------------------------------------------------------------

    private function buildSnapshot(PluginExecutionContext $context): string
    {
        $tz  = $this->resolveTimezone($context);
        $now = Carbon::now($tz);

        $parts = [];

        $parts[] = $now->format('D, d M Y') . ' · ' . $now->format('H:i');
        $parts[] = $this->timeOfDay($now);
        $parts[] = 'day '   . $this->dayPercent($now)  . '%';
        $parts[] = 'week '  . $this->weekPercent($now) . '%';
        $parts[] = 'year '  . $this->yearPercent($now) . '%';

        $age = $this->agentAge($context, $now);
        if ($age !== null) {
            $parts[] = 'my age ' . $age;
        }

        $pause = $this->pauseSinceLastMessage($context, $now);
        if ($pause !== null) {
            $parts[] = 'pause ' . $pause;
        }

        $cycles = $this->todayCycles($context, $now);
        $parts[] = 'today ' . $cycles . ' cycle' . ($cycles !== 1 ? 's' : '');

        $lat = $context->get('latitude', '');
        $lng = $context->get('longitude', '');

        if (!empty($lat) && !empty($lng)) {
            $weather = $this->fetchWeather($context, (float) $lat, (float) $lng);

            if ($weather !== null) {
                $parts[] = $weather['condition'] . ' ' . $weather['temp'] . '°C';

                $sunset = $this->sunsetIn($now, (float) $lat, (float) $lng);
                if ($sunset !== null) {
                    $parts[] = $sunset;
                }
            }
        }

        $city = $context->get('city', 'World');
        return '[' . $city . '] ' . implode(' · ', $parts);
    }

    private function dayPercent(Carbon $now): int
    {
        $secondsInDay   = 86400;
        $secondsElapsed = $now->secondsSinceMidnight();
        return (int) round(($secondsElapsed / $secondsInDay) * 100);
    }

    private function weekPercent(Carbon $now): int
    {
        $dayOfWeek     = $now->isoWeekday();
        $secondsInWeek = 86400 * 7;
        $elapsed       = ($dayOfWeek - 1) * 86400 + $now->secondsSinceMidnight();
        return (int) round(($elapsed / $secondsInWeek) * 100);
    }

    private function yearPercent(Carbon $now): int
    {
        $startOfYear  = $now->copy()->startOfYear();
        $endOfYear    = $now->copy()->endOfYear();
        $totalSeconds = $endOfYear->diffInSeconds($startOfYear);
        $elapsed      = $now->diffInSeconds($startOfYear);
        return (int) round(($elapsed / $totalSeconds) * 100);
    }

    private function agentAge(PluginExecutionContext $context, Carbon $now): ?string
    {
        $birthDate = $context->get('birth_date', '');

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

    private function resolveTimezone(PluginExecutionContext $context): string
    {
        $tz = $context->get('timezone', '');
        return !empty($tz) ? $tz : config('app.timezone', 'UTC');
    }

    private function pauseSinceLastMessage(PluginExecutionContext $context, Carbon $now): ?string
    {
        $last = Message::forPreset($context->preset->getId())
            ->whereIn('role', ['thinking', 'command'])
            ->latest()
            ->first();

        if ($last === null) {
            return null;
        }

        $diff = (int) Carbon::parse($last->created_at)->diffInSeconds($now);

        return $this->formatDuration($diff);
    }

    private function todayCycles(PluginExecutionContext $context, Carbon $now): int
    {
        return Message::forPreset($context->preset->getId())
            ->whereIn('role', ['thinking', 'command'])
            ->whereDate('created_at', $now->toDateString())
            ->count();
    }

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
        return match (true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 22 => 'evening',
            default                   => 'night',
        };
    }

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
