<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OpenApiController;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    public function test_yaml_endpoint_is_reachable_without_an_api_key(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $response = $this->get('/api/openapi.yaml');

        $response->assertOk();
        $this->assertStringContainsString('application/yaml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('nrl-predictor-openapi.yaml', $response->headers->get('Content-Disposition'));

        $spec = Yaml::parse($response->getContent());
        $this->assertSame('NRL Try Predictor API', $spec['info']['title']);
    }

    public function test_json_endpoint_returns_the_same_document_as_valid_json(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $response = $this->get('/api/openapi.json');
        $response->assertOk();

        $spec = json_decode($response->getContent(), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(Yaml::parseFile(OpenApiController::specPath()), $spec);

        // The disclaimer middleware must not corrupt the document.
        $this->assertArrayNotHasKey('responsible_gambling', $spec);
    }

    public function test_download_flag_forces_an_attachment(): void
    {
        $this->get('/api/openapi.yaml')
            ->assertHeader('Content-Disposition', 'inline; filename="nrl-predictor-openapi.yaml"');

        $this->get('/api/openapi.json?download=1')
            ->assertHeader('Content-Disposition', 'attachment; filename="nrl-predictor-openapi.json"');
    }

    public function test_docs_page_renders(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $this->get('/api/docs')
            ->assertOk()
            ->assertSee('API reference', false)
            ->assertSee('/api/openapi.json', false);
    }

    public function test_every_api_route_is_documented(): void
    {
        $spec = Yaml::parseFile(OpenApiController::specPath());

        foreach ($this->apiRoutes() as $path => $methods) {
            $this->assertArrayHasKey($path, $spec['paths'], "Route {$path} is missing from the OpenAPI spec.");

            foreach ($methods as $method) {
                $this->assertArrayHasKey(
                    $method,
                    $spec['paths'][$path],
                    "Route {$method} {$path} is missing from the OpenAPI spec."
                );
            }
        }
    }

    public function test_the_spec_documents_no_routes_that_do_not_exist(): void
    {
        $spec = Yaml::parseFile(OpenApiController::specPath());
        $routes = $this->apiRoutes();

        foreach ($spec['paths'] as $path => $operations) {
            $this->assertArrayHasKey($path, $routes, "Spec documents {$path}, which is not a registered route.");

            foreach (array_keys($operations) as $method) {
                $this->assertContains(
                    $method,
                    $routes[$path],
                    "Spec documents {$method} {$path}, which is not a registered route."
                );
            }
        }
    }

    /**
     * Registered /api routes as `['/api/v1/teams' => ['get'], ...]`.
     *
     * @return array<string, list<string>>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $methods = array_map(
                'strtolower',
                array_diff($route->methods(), ['HEAD', 'OPTIONS'])
            );

            $routes['/'.$route->uri()] = array_values($methods);
        }

        return $routes;
    }
}
