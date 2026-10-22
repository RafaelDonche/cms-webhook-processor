<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Middleware\AssignRequestId;
use App\Models\Content;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class ReceiveCmsEventsControllerTest extends TestCase
{
    use InteractsWithCmsWebhook;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCmsWebhookCredentials();
    }

    public function test_applies_a_mixed_batch_and_summarizes_each_event(): void
    {
        $response = $this->postCmsEvents([
            self::publishEvent(),
            self::publishEvent(['type' => 'unpublish', 'id' => 'content-456', 'version' => 4]),
            self::deleteEvent(),
        ]);

        $response->assertOk()->assertExactJson(['data' => [
            'batch_id' => $response->headers->get(AssignRequestId::HEADER),
            'received' => 3,
            'applied' => 3,
            'ignored' => 0,
            'events' => [
                ['id' => 'content-123', 'type' => 'publish', 'version' => 2, 'outcome' => 'applied'],
                ['id' => 'content-456', 'type' => 'unpublish', 'version' => 4, 'outcome' => 'applied'],
                ['id' => 'content-789', 'type' => 'delete', 'version' => 5, 'outcome' => 'applied'],
            ],
        ]]);
        $this->assertDatabaseHas('contents', ['external_id' => 'content-123', 'version' => 2, 'status' => ContentStatus::Published]);
        $this->assertDatabaseHas('contents', ['external_id' => 'content-456', 'version' => 4, 'status' => ContentStatus::Unpublished]);
        $this->assertDatabaseHas('contents', ['external_id' => 'content-789', 'version' => 5, 'status' => ContentStatus::Deleted, 'payload' => null]);
    }

    public function test_identifies_each_delivery_with_a_request_id(): void
    {
        $response = $this->postCmsEvents([self::publishEvent()]);

        $this->assertTrue(Str::isUuid($response->headers->get(AssignRequestId::HEADER)));
    }

    public function test_reports_a_redelivered_batch_as_ignored_duplicates_without_changing_content(): void
    {
        $batch = [self::publishEvent(['payload' => ['title' => 'Original']])];
        $this->postCmsEvents($batch)->assertOk();

        $this->postCmsEvents($batch)
            ->assertOk()
            ->assertJsonPath('data.applied', 0)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.events.0.outcome', 'ignored')
            ->assertJsonPath('data.events.0.reason', 'duplicate');

        $this->assertDatabaseCount('contents', 1);
        $this->assertSame('Original', Content::query()->sole()->payload?->title);
    }

    public function test_reports_an_older_delivery_arriving_late_as_stale(): void
    {
        $this->postCmsEvents([self::publishEvent(['version' => 5, 'payload' => ['title' => 'Version 5']])])->assertOk();

        $this->postCmsEvents([self::publishEvent(['version' => 3, 'payload' => ['title' => 'Version 3']])])
            ->assertOk()
            ->assertJsonPath('data.events.0.outcome', 'ignored')
            ->assertJsonPath('data.events.0.reason', 'stale');

        $content = Content::query()->sole();
        $this->assertSame(5, $content->version);
        $this->assertSame('Version 5', $content->payload?->title);
    }

    public function test_returns_500_and_applies_nothing_when_the_batch_fails_midway(): void
    {
        Exceptions::fake();
        Content::creating(static function (Content $content): void {
            if ($content->external_id === 'content-2') {
                throw new RuntimeException('Simulated storage failure.');
            }
        });

        $response = $this->postCmsEvents([
            self::publishEvent(['id' => 'content-1']),
            self::publishEvent(['id' => 'content-2']),
        ]);

        $response->assertInternalServerError()->assertExactJson([
            'message' => 'The batch could not be processed and no events were applied. Retry the delivery.',
            'batch_id' => $response->headers->get(AssignRequestId::HEADER),
        ]);
        $this->assertDatabaseEmpty('contents');
        Exceptions::assertNothingReported();
    }

    public function test_returns_429_once_the_delivery_rate_limit_is_exceeded(): void
    {
        config(['cms.webhook.rate_limit_per_minute' => 2]);

        $this->postCmsEvents([self::publishEvent()])->assertOk();
        $this->postCmsEvents([self::publishEvent()])->assertOk();
        $this->postCmsEvents([self::publishEvent()])->assertTooManyRequests();
    }
}
