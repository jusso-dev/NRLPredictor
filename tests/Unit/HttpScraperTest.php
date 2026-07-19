<?php

namespace Tests\Unit;

use App\Support\HttpScraper;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HttpScraperTest extends TestCase
{
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
