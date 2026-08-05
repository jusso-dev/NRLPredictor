<?php

namespace Tests\Feature;

use App\Http\Middleware\ApiKeyAuth;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    /** The methodology endpoint is static, so these tests need no database. */
    private const ENDPOINT = '/api/v1/methodology';

    public function test_api_is_open_when_no_key_is_configured(): void
    {
        config(['services.api.keys' => '']);

        $this->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_request_without_a_key_is_rejected_once_a_key_is_configured(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $this->getJson(self::ENDPOINT)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid or missing API key.');
    }

    public function test_wrong_key_is_rejected(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'nope'])
            ->assertStatus(401);
    }

    public function test_key_is_accepted_in_the_x_api_key_header(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'super-secret'])
            ->assertOk();
    }

    public function test_key_is_accepted_as_a_bearer_token(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $this->getJson(self::ENDPOINT, ['Authorization' => 'Bearer super-secret'])
            ->assertOk();
    }

    public function test_any_configured_key_is_accepted_so_keys_can_be_rotated(): void
    {
        config(['services.api.keys' => 'old-key, new-key']);

        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'old-key'])->assertOk();
        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'new-key'])->assertOk();
        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'retired-key'])->assertStatus(401);
    }

    public function test_keys_tolerate_padding_so_a_pasted_env_line_still_works(): void
    {
        config(['services.api.keys' => "  ios-key ,\thermes-key  "]);

        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'ios-key'])->assertOk();
        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'hermes-key'])->assertOk();

        // The presented key is trimmed as well, so a header pasted with stray
        // whitespace still authenticates.
        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => ' ios-key '])->assertOk();
        $this->getJson(self::ENDPOINT, [ApiKeyAuth::HEADER => 'ios-key-2'])->assertStatus(401);
    }

    public function test_chat_endpoint_is_protected_too(): void
    {
        config(['services.api.keys' => 'super-secret']);

        $this->postJson('/api/chat', ['message' => 'hello'])->assertStatus(401);
    }

    public function test_browser_chat_route_stays_open_because_it_is_session_and_csrf_protected(): void
    {
        config(['services.api.keys' => 'super-secret']);

        // Empty body fails validation (422) rather than auth (401), which is the
        // point: the web chat page keeps working once a key is configured.
        $this->postJson('/chat/send', [])->assertStatus(422);
    }
}
