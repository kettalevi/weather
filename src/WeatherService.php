<?php
declare(strict_types=1);

/**
 * Talks to the free Open-Meteo APIs (no API key required).
 * Responses are cached on disk to be polite to the API and make the app fast.
 */
final class WeatherService
{
    private const GEOCODE_URL  = 'https://geocoding-api.open-meteo.com/v1/search';
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(
        private string $cacheDir,
        private int $cacheTtl = 600
    ) {
    }

    /** @return array{name:string,country:string,admin:string,lat:float,lon:float}|null */
    public function findCity(string $query): ?array
    {
        $data = $this->get(self::GEOCODE_URL, [
            'name' => $query, 'count' => 1, 'language' => 'en', 'format' => 'json',
        ], 86400);

        $r = $data['results'][0] ?? null;
        if ($r === null) {
            return null;
        }
        return [
            'name'    => (string) $r['name'],
            'country' => (string) ($r['country'] ?? ''),
            'admin'   => (string) ($r['admin1'] ?? ''),
            'lat'     => (float) $r['latitude'],
            'lon'     => (float) $r['longitude'],
        ];
    }

    /** @return array<string,mixed> */
    public function forecast(float $lat, float $lon, string $units = 'metric'): array
    {
        $imperial = $units === 'imperial';
        return $this->get(self::FORECAST_URL, [
            'latitude'  => $lat,
            'longitude' => $lon,
            'current'   => 'temperature_2m,apparent_temperature,relative_humidity_2m,'
                         . 'weather_code,wind_speed_10m,is_day',
            'hourly'    => 'temperature_2m,precipitation_probability,precipitation,weather_code,is_day',
            'daily'     => 'weather_code,temperature_2m_max,temperature_2m_min,'
                         . 'precipitation_probability_max,sunrise,sunset',
            'timezone'  => 'auto',
            'forecast_days' => 7,
            'temperature_unit' => $imperial ? 'fahrenheit' : 'celsius',
            'wind_speed_unit'  => $imperial ? 'mph' : 'kmh',
        ], $this->cacheTtl);
    }

    /**
     * Next $hours entries of the hourly forecast, starting at the current local hour.
     * Open-Meteo returns local times (timezone=auto), so plain string comparison works.
     *
     * @param array<string,mixed> $weather result of forecast()
     * @return list<array{time:string,temp:float,pop:int,rain:float,code:int,day:bool}>
     */
    public static function nextHours(array $weather, int $hours = 24): array
    {
        $h = $weather['hourly'] ?? null;
        if (!is_array($h) || empty($h['time'])) {
            return [];
        }
        $now = substr((string) ($weather['current']['time'] ?? ''), 0, 13) . ':00';
        $out = [];
        foreach ($h['time'] as $i => $t) {
            if ($t < $now) {
                continue;
            }
            $out[] = [
                'time' => (string) $t,
                'temp' => (float) ($h['temperature_2m'][$i] ?? 0),
                'pop'  => (int) ($h['precipitation_probability'][$i] ?? 0),
                'rain' => (float) ($h['precipitation'][$i] ?? 0),
                'code' => (int) ($h['weather_code'][$i] ?? 0),
                'day'  => (bool) ($h['is_day'][$i] ?? 1),
            ];
            if (count($out) >= $hours) {
                break;
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} [description, emoji] */
    public static function describe(int $code, bool $day = true): array
    {
        return match (true) {
            $code === 0                => ['Clear sky', $day ? '☀️' : '🌙'],
            $code === 1                => ['Mainly clear', $day ? '🌤️' : '🌙'],
            $code === 2                => ['Partly cloudy', '⛅'],
            $code === 3                => ['Overcast', '☁️'],
            in_array($code, [45, 48])  => ['Fog', '🌫️'],
            $code >= 51 && $code <= 57 => ['Drizzle', '🌦️'],
            $code >= 61 && $code <= 67 => ['Rain', '🌧️'],
            $code >= 71 && $code <= 77 => ['Snow', '❄️'],
            $code >= 80 && $code <= 82 => ['Rain showers', '🌧️'],
            $code === 85 || $code === 86 => ['Snow showers', '🌨️'],
            $code >= 95                => ['Thunderstorm', '⛈️'],
            default                    => ['Unknown', '❔'],
        };
    }

    /** @param array<string,scalar> $params @return array<string,mixed> */
    private function get(string $url, array $params, int $ttl): array
    {
        $full = $url . '?' . http_build_query($params);
        $file = rtrim($this->cacheDir, '/') . '/' . sha1($full) . '.json';

        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $body = $this->fetch($full);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Weather service returned an invalid response.');
        }
        if (is_dir($this->cacheDir) && is_writable($this->cacheDir)) {
            @file_put_contents($file, $body, LOCK_EX);
        }
        return $data;
    }

    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        } else {
            $ctx  = stream_context_create(['http' => ['timeout' => 10]]);
            $body = @file_get_contents($url, false, $ctx);
            $code = $body === false ? 0 : 200;
        }
        if ($body === false || $code !== 200) {
            throw new RuntimeException('Could not reach the weather service. Please try again later.');
        }
        return (string) $body;
    }
}
