<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class ConsumerAuthenticationTest extends TestCase
{
    use InteractsWithCmsWebhook;
    use LazilyRefreshDatabase;

    public function test_accepts_a_valid_consumer_token(): void
    {
        $token = User::factory()->create()->createToken('test', expiresAt: now()->addDay())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/contents')->assertOk();
    }

    #[DataProvider('apiRoutes')]
    public function test_returns_401_without_a_token(string $method, string $uri): void
    {
        $this->call($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function apiRoutes(): array
    {
        return [
            'list contents' => ['GET', '/api/v1/contents'],
            'show content' => ['GET', '/api/v1/contents/content-123'],
            'disable content' => ['PUT', '/api/v1/contents/content-123/disabled'],
            'enable content' => ['DELETE', '/api/v1/contents/content-123/disabled'],
        ];
    }

    public function test_returns_401_for_an_unknown_token(): void
    {
        $this->withToken('1|'.str_repeat('a', 40))->getJson('/api/v1/contents')->assertUnauthorized();
    }

    public function test_returns_401_for_an_expired_token(): void
    {
        $token = User::factory()->create()->createToken('test', expiresAt: now()->addDay())->plainTextToken;
        $this->travel(2)->days();

        $this->withToken($token)->getJson('/api/v1/contents')->assertUnauthorized();
    }

    public function test_returns_401_when_the_cms_credentials_are_used_on_the_consumer_api(): void
    {
        $this->configureCmsWebhookCredentials();

        $this->withBasicAuth(self::CMS_USERNAME, self::CMS_PASSWORD)->getJson('/api/v1/contents')->assertUnauthorized();
    }

    public function test_returns_401_when_a_consumer_token_is_used_on_the_cms_webhook(): void
    {
        $this->configureCmsWebhookCredentials();
        $token = User::factory()->admin()->create()->createToken('test', expiresAt: now()->addDay())->plainTextToken;

        $this->withToken($token)->postJson('/cms/events', [self::publishEvent()])->assertUnauthorized();

        $this->assertDatabaseEmpty('contents');
    }
}
