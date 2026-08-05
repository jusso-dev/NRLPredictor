<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the hand-maintained OpenAPI document.
 *
 * These routes are exempt from ApiKeyAuth so tooling and agents can discover the
 * API before they hold a key; the endpoints the spec describes still need one.
 * tests/Feature/OpenApiSpecTest.php fails if the spec drifts from routes/api.php.
 */
class OpenApiController extends Controller
{
    public const FILENAME = 'nrl-predictor-openapi';

    public function yaml(Request $request): Response
    {
        return response(file_get_contents(self::specPath()), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => self::disposition($request, 'yaml'),
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function json(Request $request): Response
    {
        $spec = Yaml::parseFile(self::specPath());

        $encoded = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response($encoded, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => self::disposition($request, 'json'),
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function docs(): Response
    {
        return response(view('openapi.docs')->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public static function specPath(): string
    {
        return resource_path('openapi/openapi.yaml');
    }

    /** `?download=1` forces a save dialog; otherwise the document renders inline. */
    private static function disposition(Request $request, string $extension): string
    {
        $mode = $request->boolean('download') ? 'attachment' : 'inline';

        return $mode.'; filename="'.self::FILENAME.'.'.$extension.'"';
    }
}
