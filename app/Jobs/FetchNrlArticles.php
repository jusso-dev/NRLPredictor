<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Article;
use App\Models\Team;
use App\Support\HttpScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class FetchNrlArticles implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 600;
    public int $tries = 1;
    public int $uniqueFor = 660; // > worst case: 1 try x 600s timeout

    public function uniqueId(): string
    {
        return 'fetch:nrl-articles';
    }

    public function handle(HttpScraper $http): void
    {
        $this->startLog('nrl.com/news');
        $records = 0;

        try {
            $teams = Team::all();
            foreach ($teams as $team) {
                $records += $this->fetchTeamArticles($http, $team);
            }
            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    protected function fetchTeamArticles(HttpScraper $http, Team $team): int
    {
        $url = sprintf('https://www.nrl.com/news/?team=%s', $team->nrl_slug);
        $crawler = $http->crawl($url);
        if (! $crawler) {
            return 0;
        }

        $count = 0;
        $year = now()->year;

        // Find all news article links for this year
        $crawler->filter("a[href*=\"/news/{$year}\"]")->slice(0, 8)->each(function (Crawler $node) use (&$count, $team) {
            $href = $node->attr('href');
            if (! $href) {
                return;
            }

            $title = trim($node->text(''));
            if ($title === '' || Str::length($title) < 10) {
                return;
            }

            // Skip video-only entries (very short titles like "00:14 Instant Highlights")
            if (preg_match('/^\d{2}:\d{2}\s/', $title)) {
                return;
            }

            $fullUrl = Str::startsWith($href, 'http') ? $href : 'https://www.nrl.com' . $href;

            $existing = Article::where('url', $fullUrl)->first();
            $teamTags = collect($existing?->team_tags ?? [])->push($team->id)->unique()->values()->all();

            if ($existing) {
                $existing->update(['team_tags' => $teamTags]);
                $count++;
                return;
            }

            Article::create([
                'url' => $fullUrl,
                'title' => Str::limit($title, 250, ''),
                'content' => '',
                'team_tags' => [$team->id],
                'published_at' => $this->extractDateFromUrl($fullUrl),
                'fetched_at' => now(),
            ]);
            $count++;
        });

        return $count;
    }

    protected function extractDateFromUrl(string $url): ?string
    {
        // Extract date from URL pattern: /news/2026/04/19/...
        if (preg_match('#/news/(\d{4})/(\d{2})/(\d{2})/#', $url, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return null;
    }
}
