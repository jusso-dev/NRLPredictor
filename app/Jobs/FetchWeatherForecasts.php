<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Models\Round;
use App\Models\WeatherForecast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetch weather forecasts for upcoming matches using Open-Meteo API
 * (free, no API key, returns JSON).
 */
class FetchWeatherForecasts implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 120;
    public int $tries = 1;
    public int $uniqueFor = 300; // > worst case: 120s timeout

    // Venue → lat/lon mapping for weather lookups
    protected const VENUE_COORDS = [
        'Accor Stadium'                    => [-33.847, 151.063],
        'Allianz Stadium'                  => [-33.888, 151.224],
        'CommBank Stadium'                 => [-33.808, 151.064],
        'BlueBet Stadium'                  => [-33.750, 150.687],
        'McDonald Jones Stadium'           => [-32.923, 151.766],
        'Campbelltown Sports Stadium'      => [-34.076, 150.822],
        '4 Pines Park'                     => [-33.797, 151.268],
        'Leichhardt Oval'                  => [-33.883, 151.154],
        'WIN Stadium'                      => [-34.441, 150.879],
        'Suncorp Stadium'                  => [-27.465, 153.010],
        'Queensland Country Bank Stadium'  => [-19.260, 146.790],
        'Cbus Super Stadium'               => [-28.007, 153.361],
        'Kayo Stadium'                     => [-27.560, 153.068],
        'TIO Stadium'                      => [-12.429, 130.843],
        'AAMI Park'                        => [-37.825, 144.983],
        'GIO Stadium'                      => [-35.257, 149.098],
        'Go Media Stadium'                 => [-36.917, 174.777],
        'Mt Smart Stadium'                 => [-36.917, 174.796],
    ];

    public function uniqueId(): string
    {
        return 'fetch:weather-forecasts';
    }

    public function handle(): void
    {
        $round = Round::current();
        if (! $round) {
            return;
        }

        $this->startLog('open-meteo.com');
        $records = 0;

        try {
            $matches = Matchup::where('round_id', $round->id)
                ->where('status', 'upcoming')
                ->whereNotNull('kickoff_at')
                ->whereNotNull('venue')
                ->get();

            foreach ($matches as $match) {
                if ($this->fetchForecast($match)) {
                    $records++;
                }
            }

            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    protected function fetchForecast(Matchup $match): bool
    {
        $coords = $this->resolveCoords($match->venue);
        if (! $coords) {
            Log::info("FetchWeather: no coords for venue '{$match->venue}'");
            return false;
        }

        [$lat, $lon] = $coords;
        $date = $match->kickoff_at->format('Y-m-d');

        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&hourly=temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m&forecast_days=7&timezone=auto',
            $lat, $lon
        );

        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return false;
            }

            $data = $response->json();
            $hourly = $data['hourly'] ?? [];
            $times = $hourly['time'] ?? [];

            // Find the hour closest to kickoff
            $kickoffHour = $match->kickoff_at->format('Y-m-d\TH:00');
            $index = array_search($kickoffHour, $times);
            if ($index === false) {
                // Try to find the closest hour
                foreach ($times as $i => $t) {
                    if (str_starts_with($t, $date)) {
                        $hour = (int) substr($t, 11, 2);
                        $kickoffLocalHour = (int) $match->kickoff_at->timezone($data['timezone'] ?? 'UTC')->format('H');
                        if (abs($hour - $kickoffLocalHour) <= 1) {
                            $index = $i;
                            break;
                        }
                    }
                }
            }

            if ($index === false) {
                return false;
            }

            $temp = $hourly['temperature_2m'][$index] ?? null;
            $rain = $hourly['precipitation'][$index] ?? 0;
            $humidity = $hourly['relative_humidity_2m'][$index] ?? null;
            $wind = $hourly['wind_speed_10m'][$index] ?? null;

            // Sum rainfall over 6 hours around kickoff
            $rainSum = 0;
            for ($i = max(0, $index - 3); $i <= min(count($times) - 1, $index + 3); $i++) {
                $rainSum += ($hourly['precipitation'][$i] ?? 0);
            }

            WeatherForecast::updateOrCreate(
                ['match_id' => $match->id],
                [
                    'temp_c' => $temp,
                    'rainfall_mm_6h' => round($rainSum, 1),
                    'humidity_pct' => $humidity,
                    'wind_kph' => $wind ? (int) round($wind) : null,
                    'is_wet' => $rainSum > 2,
                    'is_hot' => ($temp ?? 0) > 30,
                    'captured_at' => now(),
                ],
            );

            return true;
        } catch (Throwable $e) {
            Log::warning("FetchWeather: failed for {$match->venue}: {$e->getMessage()}");
            return false;
        }
    }

    protected function resolveCoords(?string $venue): ?array
    {
        if (! $venue) {
            return null;
        }

        // Exact match
        if (isset(self::VENUE_COORDS[$venue])) {
            return self::VENUE_COORDS[$venue];
        }

        // Fuzzy match
        foreach (self::VENUE_COORDS as $name => $coords) {
            if (str_contains(strtolower($venue), strtolower(explode(' ', $name)[0]))) {
                return $coords;
            }
        }

        return null;
    }
}
