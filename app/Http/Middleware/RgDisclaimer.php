<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inject responsible gambling disclaimer into HTML responses
 * and JSON API responses.
 */
class RgDisclaimer
{
    public const FOOTER_HTML = '<div class="rg-disclaimer mt-8 border-t border-ink-600 pt-4 pb-2 text-center text-xs text-bone-500">'
        . '<p>For informational use only. Gambling involves real financial risk.</p>'
        . '<p>If you need support, call <strong>1800 858 858</strong> or visit '
        . '<a href="https://www.betstop.gov.au" class="text-gold-500 hover:underline" target="_blank" rel="noopener">www.betstop.gov.au</a></p>'
        . '</div>';

    public const DISCLAIMER_TEXT = 'For informational use only. Gambling involves real financial risk. If you need support, call 1800 858 858 or visit www.betstop.gov.au.';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // For JSON responses (API), add disclaimer field
        if ($request->expectsJson() || $request->is('api/*')) {
            if ($response->headers->get('Content-Type') === 'application/json') {
                $data = json_decode($response->getContent(), true);
                if (is_array($data)) {
                    $data['responsible_gambling'] = self::DISCLAIMER_TEXT;
                    $response->setContent(json_encode($data));
                }
            }
        }

        return $response;
    }
}
