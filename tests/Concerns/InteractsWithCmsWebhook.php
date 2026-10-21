<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

trait InteractsWithCmsWebhook
{
    protected const string CMS_USERNAME = 'cms-test';

    protected const string CMS_PASSWORD = 'cms-test-secret';

    protected function configureCmsWebhookCredentials(): void
    {
        config([
            'cms.webhook.username' => self::CMS_USERNAME,
            'cms.webhook.password' => self::CMS_PASSWORD,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    protected function postCmsEvents(array $body): TestResponse
    {
        return $this->withBasicAuth(self::CMS_USERNAME, self::CMS_PASSWORD)->postJson('/cms/events', $body);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    protected function postRawCmsBody(string $content): TestResponse
    {
        $server = $this->transformHeadersToServerVars([
            'Authorization' => 'Basic '.base64_encode(self::CMS_USERNAME.':'.self::CMS_PASSWORD),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        return $this->call('POST', '/cms/events', server: $server, content: $content);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function publishEvent(array $overrides = []): array
    {
        return array_merge([
            'type' => 'publish',
            'id' => 'content-123',
            'version' => 2,
            'timestamp' => '2026-01-01T10:00:00Z',
            'payload' => ['title' => 'Example content', 'description' => 'Some content'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function deleteEvent(array $overrides = []): array
    {
        return array_merge([
            'type' => 'delete',
            'id' => 'content-789',
            'version' => 5,
            'timestamp' => '2026-01-01T10:10:00Z',
        ], $overrides);
    }
}
