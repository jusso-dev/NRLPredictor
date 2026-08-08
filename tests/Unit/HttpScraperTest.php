<?php

namespace Tests\Unit;

use App\Support\HttpScraper;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HttpScraperTest extends TestCase
{
    public function test_it_uses_an_api_client_identity_for_espn_json(): void
    {
        config()->set('cache.default', 'array');
        Http::fake([
            'site.api.espn.com/*' => Http::response(['events' => []], 200),
        ]);

        (new HttpScraper)->json('https://site.api.espn.com/test', ['events']);

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'curl/8.14.1'));
    }

    public function test_it_rejects_html_returned_by_a_json_endpoint(): void
    {
        config()->set('cache.default', 'array');
        Http::fake([
            'https://example.test/draw' => Http::response('<html>login</html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected JSON');

        (new HttpScraper)->json('https://example.test/draw', ['events']);
    }
}
