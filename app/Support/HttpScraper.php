<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Rate-limited HTTP client for scraping public NRL pages.
 * Enforces 1 request per 5 seconds per domain via a Cache lock.
 */
class HttpScraper
{
    public const USER_AGENT = 'NRLTryPredictor/1.0';
    public const TIMEOUT = 30;
    public const DOMAIN_MIN_INTERVAL = 5;

    public function get(string $url): Response
    {
        $this->throttle($url);

        return $this->client()->get($url);
    }

    public function crawl(string $url): ?Crawler
    {
        $response = $this->get($url);
        if (! $response->successful()) {
            return null;
        }
        return new Crawler($response->body(), $url);
    }

    /**
     * Fetch a JSON document and fail closed when an upstream service responds
     * with HTML, an error document, or a changed payload shape. A 200 status
     * by itself is not evidence that a data fetch succeeded.
     *
     * @param array<int, string> $requiredPaths Dot-notation paths that must be present.
     * @return array<string, mixed>
     */
    public function json(string $url, array $requiredPaths = []): array
    {
        $response = $this->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Unexpected HTTP {$response->status()} from {$url}");
        }

        $contentType = Str::lower((string) $response->header('Content-Type'));
        if (! str_contains($contentType, 'json')) {
            throw new RuntimeException("Expected JSON from {$url}; received {$contentType} instead");
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException("Invalid JSON document from {$url}");
        }

        foreach ($requiredPaths as $path) {
            if (data_get($data, $path) === null) {
                throw new RuntimeException("JSON response from {$url} is missing required field {$path}");
            }
        }

        return $data;
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/json',
            'Accept-Language' => 'en-AU,en;q=0.9',
        ])
        ->timeout(self::TIMEOUT)
        ->retry(2, 1000, throw: false);
    }

    protected function throttle(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $key = 'scrape:last:' . Str::slug($host);
        $last = (int) Cache::get($key, 0);
        $elapsed = time() - $last;
        if ($last > 0 && $elapsed < self::DOMAIN_MIN_INTERVAL) {
            $wait = self::DOMAIN_MIN_INTERVAL - $elapsed;
            sleep(max(1, $wait));
        }
        Cache::put($key, time(), 60);
    }
}
