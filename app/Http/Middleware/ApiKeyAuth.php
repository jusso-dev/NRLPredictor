<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret auth for the JSON API.
 *
 * Clients present the key as `X-API-Key: <key>` or `Authorization: Bearer <key>`.
 * Multiple keys can be configured (comma separated) so a key can be rotated
 * without downtime. When no key is configured the API stays open, which keeps
 * local development and the docker-compose default working unchanged.
 */
class ApiKeyAuth
{
    public const HEADER = 'X-API-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $keys = self::configuredKeys();

        if ($keys === []) {
            return $next($request);
        }

        $presented = self::presentedKey($request);

        if ($presented === null || ! self::matches($presented, $keys)) {
            return response()->json([
                'message' => 'Invalid or missing API key.',
            ], 401, ['WWW-Authenticate' => 'Bearer realm="nrl-api"']);
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    public static function configuredKeys(): array
    {
        $raw = (string) config('services.api.keys', '');

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $key): bool => $key !== ''
        ));
    }

    private static function presentedKey(Request $request): ?string
    {
        $header = trim((string) $request->header(self::HEADER, ''));
        if ($header !== '') {
            return $header;
        }

        $bearer = trim((string) $request->bearerToken());

        return $bearer !== '' ? $bearer : null;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function matches(string $presented, array $keys): bool
    {
        $matched = false;

        // Compare against every key so the work done is independent of which
        // key matched (or whether one matched at all).
        foreach ($keys as $key) {
            if (hash_equals($key, $presented)) {
                $matched = true;
            }
        }

        return $matched;
    }
}
