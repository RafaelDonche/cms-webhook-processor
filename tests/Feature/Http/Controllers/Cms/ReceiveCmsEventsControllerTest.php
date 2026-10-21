<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Cms;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class ReceiveCmsEventsControllerTest extends TestCase
{
    use InteractsWithCmsWebhook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCmsWebhookCredentials();
    }

    public function test_accepts_a_mixed_batch_of_publish_unpublish_and_delete_events(): void
    {
        $this->postCmsEvents([
            self::publishEvent(),
            self::publishEvent(['type' => 'unpublish', 'id' => 'content-456', 'version' => 4]),
            self::deleteEvent(),
        ])
            ->assertOk()
            ->assertJsonPath('data.received', 3);
    }

    public function test_identifies_each_delivery_with_a_request_id(): void
    {
        $response = $this->postCmsEvents([self::publishEvent()]);

        $this->assertTrue(Str::isUuid($response->headers->get(AssignRequestId::HEADER)));
    }

    public function test_returns_429_once_the_delivery_rate_limit_is_exceeded(): void
    {
        config(['cms.webhook.rate_limit_per_minute' => 2]);

        $this->postCmsEvents([self::publishEvent()])->assertOk();
        $this->postCmsEvents([self::publishEvent()])->assertOk();
        $this->postCmsEvents([self::publishEvent()])->assertTooManyRequests();
    }
}
