<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class CrossOriginRequestTest extends TestCase
{
    use InteractsWithCmsWebhook;
    use LazilyRefreshDatabase;

    private const string ALLOWED_ORIGIN = 'https://admin.example.com';

    public function test_does_not_allow_any_origin_by_default(): void
    {
        $this->withToken($this->consumerToken())
            ->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->getJson('/api/v1/contents')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_allows_a_configured_origin_to_read_the_consumer_api(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withToken($this->consumerToken())
            ->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->getJson('/api/v1/contents');

        $response->assertOk()->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN);
        $this->assertStringContainsString('X-Request-Id', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_does_not_allow_an_origin_that_is_not_configured(): void
    {
        $otherOrigin = 'https://attacker.example.net';
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->withToken($this->consumerToken())
            ->withHeader('Origin', $otherOrigin)
            ->getJson('/api/v1/contents');

        $response->assertOk();
        $this->assertNotSame($otherOrigin, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_answers_the_preflight_of_a_configured_origin_with_the_allowed_methods_and_headers(): void
    {
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $response = $this->call('OPTIONS', '/api/v1/contents/content-123/disabled', server: [
            'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN)
            ->assertHeader('Access-Control-Allow-Methods', 'GET, PUT, DELETE')
            ->assertHeader('Access-Control-Allow-Headers', 'accept, authorization, content-type');
    }

    public function test_never_allows_cross_origin_requests_to_the_cms_webhook(): void
    {
        $this->configureCmsWebhookCredentials();
        config()->set('cors.allowed_origins', [self::ALLOWED_ORIGIN]);

        $this->withHeader('Origin', self::ALLOWED_ORIGIN)
            ->postCmsEvents([self::publishEvent()])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function consumerToken(): string
    {
        return User::factory()->create()->createToken('test', expiresAt: now()->addDay())->plainTextToken;
    }
}
