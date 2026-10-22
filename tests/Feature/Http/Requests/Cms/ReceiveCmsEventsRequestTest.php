<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Requests\Cms;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesCmsLog;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class ReceiveCmsEventsRequestTest extends TestCase
{
    use CapturesCmsLog;
    use InteractsWithCmsWebhook;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCmsWebhookCredentials();
    }

    public function test_accepts_a_batch_with_the_maximum_of_100_events(): void
    {
        $this->postCmsEvents($this->batchOf(100))->assertOk();
    }

    #[DataProvider('batchesWithInvalidSize')]
    public function test_returns_422_for_batches_without_1_to_100_events(int $eventCount): void
    {
        $this->postCmsEvents($this->batchOf($eventCount))
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['events']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function batchesWithInvalidSize(): array
    {
        return [
            'empty batch' => [0],
            'more than 100 events' => [101],
        ];
    }

    #[DataProvider('bodiesThatAreNotJsonLists')]
    public function test_returns_422_when_the_body_is_not_a_json_list(string $body): void
    {
        $this->postRawCmsBody($body)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['events']);

        $this->assertDatabaseEmpty('contents');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatAreNotJsonLists(): array
    {
        return [
            'single event object' => [(string) json_encode(self::publishEvent())],
            'object with list-like keys' => [(string) json_encode(['0' => self::deleteEvent(['timestamp' => 'next monday'])], JSON_FORCE_OBJECT)],
            'malformed json' => ['[{"type": "publish"'],
            'json string' => ['"publish"'],
            'empty body' => [''],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    #[DataProvider('invalidEvents')]
    public function test_returns_422_for_an_invalid_event_field(array $event, string $invalidField): void
    {
        $this->postCmsEvents([$event])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$invalidField]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidEvents(): array
    {
        return [
            'missing type' => [Arr::except(self::publishEvent(), 'type'), 'events.0.type'],
            'unknown type' => [self::publishEvent(['type' => 'archive']), 'events.0.type'],
            'missing id' => [Arr::except(self::publishEvent(), 'id'), 'events.0.id'],
            'blank id' => [self::publishEvent(['id' => '']), 'events.0.id'],
            'numeric id' => [self::publishEvent(['id' => 123]), 'events.0.id'],
            'id longer than 255 characters' => [self::publishEvent(['id' => str_repeat('a', 256)]), 'events.0.id'],
            'missing version' => [Arr::except(self::publishEvent(), 'version'), 'events.0.version'],
            'version as string' => [self::publishEvent(['version' => '2']), 'events.0.version'],
            'version as decimal' => [self::publishEvent(['version' => 2.5]), 'events.0.version'],
            'version as boolean' => [self::publishEvent(['version' => true]), 'events.0.version'],
            'zero version' => [self::publishEvent(['version' => 0]), 'events.0.version'],
            'negative version' => [self::publishEvent(['version' => -1]), 'events.0.version'],
            'missing timestamp' => [Arr::except(self::publishEvent(), 'timestamp'), 'events.0.timestamp'],
            'timestamp without time zone' => [self::publishEvent(['timestamp' => '2026-01-01T10:00:00']), 'events.0.timestamp'],
            'publish without payload' => [Arr::except(self::publishEvent(), 'payload'), 'events.0.payload'],
            'unpublish without payload' => [Arr::except(self::publishEvent(['type' => 'unpublish']), 'payload'), 'events.0.payload'],
            'null payload' => [self::publishEvent(['payload' => null]), 'events.0.payload'],
            'list payload' => [self::publishEvent(['payload' => ['first', 'second']]), 'events.0.payload'],
            'string payload' => [self::publishEvent(['payload' => 'Example content']), 'events.0.payload'],
        ];
    }

    public function test_returns_422_for_events_that_are_not_objects(): void
    {
        $this->postCmsEvents(['publish'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0']);
    }

    public function test_returns_422_for_versions_written_as_decimal_numbers(): void
    {
        $this->postRawCmsBody('[{"type": "publish", "id": "content-123", "version": 2.0, "timestamp": "2026-01-01T10:00:00Z", "payload": {}}]')
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['events.0.version']);
    }

    public function test_accepts_timestamps_with_nanoseconds_and_lowercase_separators(): void
    {
        $this->postCmsEvents([
            self::publishEvent(['id' => 'content-1', 'timestamp' => '2026-01-01T10:00:00.123456789Z']),
            self::publishEvent(['id' => 'content-2', 'timestamp' => '2026-01-01t10:00:00z']),
        ])->assertOk();
    }

    public function test_accepts_an_empty_object_as_payload(): void
    {
        $this->postCmsEvents([self::publishEvent(['payload' => (object) []])])->assertOk();
    }

    public function test_accepts_delete_events_without_payload(): void
    {
        $this->postCmsEvents([self::deleteEvent()])->assertOk();
    }

    public function test_accepts_delete_events_with_any_payload(): void
    {
        $this->postCmsEvents([self::deleteEvent(['payload' => ['ignored']])])->assertOk();
    }

    public function test_rejects_the_whole_batch_when_one_event_is_invalid(): void
    {
        $this->postCmsEvents([
            self::publishEvent(['id' => 'content-1']),
            self::publishEvent(['id' => 'content-2', 'version' => 0]),
            self::deleteEvent(['id' => 'content-3']),
        ])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['events.1.version']);

        $this->assertDatabaseEmpty('contents');
    }

    public function test_reports_the_invalid_fields_of_every_event(): void
    {
        $this->postCmsEvents([
            self::publishEvent(['version' => 0]),
            self::deleteEvent(['timestamp' => 'yesterday']),
        ])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['events.0.version', 'events.1.timestamp']);
    }

    public function test_logs_rejected_batches_without_their_content(): void
    {
        $log = $this->captureCmsLog();

        $this->postCmsEvents([self::publishEvent(['version' => 0, 'payload' => ['title' => 'Confidential draft']])]);

        $this->assertCount(1, $log->getRecords());
        $record = $log->getRecords()[0];
        $this->assertSame('cms.batch.rejected', $record->message);
        $this->assertSame(['event_count' => 1, 'invalid_fields' => ['events.0.version']], $record->context);
        $this->assertStringNotContainsString('Confidential draft', (string) json_encode($log->getRecords()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batchOf(int $eventCount): array
    {
        $events = [];

        for ($version = 1; $version <= $eventCount; $version++) {
            $events[] = self::publishEvent(['version' => $version]);
        }

        return $events;
    }
}
