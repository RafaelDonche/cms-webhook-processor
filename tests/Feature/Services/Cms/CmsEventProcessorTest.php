<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Cms;

use App\Data\BatchResult;
use App\Data\CmsEvent;
use App\Data\EventResult;
use App\Enums\CmsEventType;
use App\Enums\ContentStatus;
use App\Exceptions\CmsBatchProcessingFailed;
use App\Models\Content;
use App\Models\User;
use App\Services\Cms\CmsEventProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use RuntimeException;
use Tests\Concerns\CapturesCmsLog;
use Tests\TestCase;

final class CmsEventProcessorTest extends TestCase
{
    use CapturesCmsLog;
    use LazilyRefreshDatabase;

    private const string BATCH_ID = '0199a8f0-0000-7000-8000-000000000001';

    private const string DEFAULT_TIMESTAMP = '2026-01-01T10:00:00Z';

    public function test_publishing_unknown_content_creates_it_at_the_version_received(): void
    {
        $batch = $this->process([$this->publishEvent('content-1', 7)]);

        $this->assertSame(['applied'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(7, $content->version);
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertSame('Version 7', $content->payload?->title);
    }

    public function test_ignores_a_redelivered_event_as_a_duplicate(): void
    {
        Content::factory()->published()->create(['external_id' => 'content-1', 'version' => 3, 'payload' => (object) ['title' => 'Original']]);

        $batch = $this->process([$this->publishEvent('content-1', 3)]);

        $this->assertSame(['ignored:duplicate'], $this->outcomes($batch));
        $this->assertSame('Original', $this->content('content-1')->payload?->title);
    }

    public function test_ignores_a_repeated_version_within_the_same_batch_even_with_another_type(): void
    {
        $batch = $this->process([
            $this->publishEvent('content-1', 2),
            $this->publishEvent('content-1', 2, title: 'Repeated'),
            $this->unpublishEvent('content-1', 2),
        ]);

        $this->assertSame(['applied', 'ignored:duplicate', 'ignored:duplicate'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertSame('Version 2', $content->payload?->title);
    }

    public function test_ignores_an_older_event_that_arrives_after_a_newer_one(): void
    {
        $batch = $this->process([
            $this->publishEvent('content-1', 5),
            $this->unpublishEvent('content-1', 3),
        ]);

        $this->assertSame(['applied', 'ignored:stale'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(5, $content->version);
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertSame('Version 5', $content->payload?->title);
    }

    public function test_accepts_versions_that_skip_intermediate_changes(): void
    {
        Content::factory()->published()->create(['external_id' => 'content-1', 'version' => 1]);

        $batch = $this->process([$this->publishEvent('content-1', 7)]);

        $this->assertSame(['applied'], $this->outcomes($batch));
        $this->assertSame(1, $batch->events[0]->previousVersion);
        $this->assertSame(7, $this->content('content-1')->version);
    }

    public function test_unpublishing_a_version_never_received_keeps_its_payload_as_the_current_content(): void
    {
        Content::factory()->published()->create(['external_id' => 'content-1', 'version' => 3, 'payload' => (object) ['title' => 'Version 3']]);

        $batch = $this->process([$this->unpublishEvent('content-1', 4)]);

        $this->assertSame(['applied'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(4, $content->version);
        $this->assertSame(ContentStatus::Unpublished, $content->status);
        $this->assertSame('Version 4', $content->payload?->title);
    }

    public function test_deleting_leaves_a_tombstone_that_older_events_cannot_restore(): void
    {
        Content::factory()->published()->create(['external_id' => 'content-1', 'version' => 3]);

        $batch = $this->process([
            $this->deleteEvent('content-1', 5),
            $this->publishEvent('content-1', 4),
            $this->unpublishEvent('content-1', 5),
        ]);

        $this->assertSame(['applied', 'ignored:stale', 'ignored:duplicate'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(5, $content->version);
        $this->assertSame(ContentStatus::Deleted, $content->status);
        $this->assertNull($content->payload);
    }

    public function test_a_delete_received_first_blocks_older_events_delivered_later(): void
    {
        $this->process([$this->deleteEvent('content-1', 5)]);

        $batch = $this->process([$this->publishEvent('content-1', 2)]);

        $this->assertSame(['ignored:stale'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(ContentStatus::Deleted, $content->status);
        $this->assertNull($content->payload);
    }

    public function test_a_newer_version_after_a_delete_restores_the_content_and_keeps_the_local_override(): void
    {
        $admin = User::factory()->admin()->create();
        Content::factory()->deleted()->disabled($admin)->create(['external_id' => 'content-1', 'version' => 5]);

        $batch = $this->process([$this->publishEvent('content-1', 6)]);

        $this->assertSame(['applied'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertSame('Version 6', $content->payload?->title);
        $this->assertTrue($content->isDisabled());
        $this->assertSame($admin->id, $content->disabled_by);
    }

    public function test_precedence_follows_the_version_and_never_the_timestamp(): void
    {
        Content::factory()->published()->create([
            'external_id' => 'content-1',
            'version' => 3,
            'source_updated_at' => CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        ]);

        $batch = $this->process([
            $this->publishEvent('content-1', 2, timestamp: '2026-12-31T00:00:00Z'),
            $this->publishEvent('content-1', 4, timestamp: '2026-01-01T00:00:00Z'),
        ]);

        $this->assertSame(['ignored:stale', 'applied'], $this->outcomes($batch));
        $content = $this->content('content-1');
        $this->assertSame(4, $content->version);
        $this->assertSame('2026-01-01T00:00:00+00:00', $content->source_updated_at?->toAtomString());
    }

    public function test_stores_a_timestamp_with_an_offset_as_the_same_instant_in_utc(): void
    {
        $this->process([$this->publishEvent('content-1', 1, timestamp: '2026-01-01T10:00:00+02:00')]);

        $this->assertSame('2026-01-01T08:00:00+00:00', $this->content('content-1')->source_updated_at?->toAtomString());
    }

    public function test_applies_the_events_of_a_batch_in_the_order_received(): void
    {
        $batch = $this->process([
            $this->publishEvent('content-1', 1),
            $this->publishEvent('content-2', 1),
            $this->unpublishEvent('content-1', 2),
            $this->publishEvent('content-1', 3),
            $this->deleteEvent('content-2', 2),
        ]);

        $this->assertSame(
            [['content-1', null], ['content-2', null], ['content-1', 1], ['content-1', 2], ['content-2', 1]],
            array_map(static fn (EventResult $event): array => [$event->externalId, $event->previousVersion], $batch->events),
        );
        $this->assertSame(ContentStatus::Published, $this->content('content-1')->status);
        $this->assertSame(ContentStatus::Deleted, $this->content('content-2')->status);
    }

    public function test_cms_events_never_reset_the_local_disable_override(): void
    {
        $admin = User::factory()->admin()->create();
        $content = Content::factory()->published()->disabled($admin)->create(['external_id' => 'content-1', 'version' => 1]);
        $disabledAt = $content->disabled_at?->toAtomString();

        $this->process([
            $this->unpublishEvent('content-1', 2),
            $this->publishEvent('content-1', 3),
        ]);

        $content->refresh();
        $this->assertSame($disabledAt, $content->disabled_at?->toAtomString());
        $this->assertSame($admin->id, $content->disabled_by);
    }

    public function test_rolls_back_the_whole_batch_when_an_event_fails(): void
    {
        Content::factory()->published()->create(['external_id' => 'content-1', 'version' => 1, 'payload' => (object) ['title' => 'Version 1']]);
        $this->failWhenCreating('content-2');

        $exception = $this->processFailingBatch([
            $this->publishEvent('content-1', 2),
            $this->publishEvent('content-2', 1),
        ]);

        $this->assertSame(self::BATCH_ID, $exception->batchId);
        $this->assertNull($exception->getPrevious());
        $this->assertDatabaseCount('contents', 1);
        $content = $this->content('content-1');
        $this->assertSame(1, $content->version);
        $this->assertSame('Version 1', $content->payload?->title);
    }

    public function test_holds_a_lock_on_every_content_of_the_batch_until_the_transaction_ends(): void
    {
        $this->process([
            $this->publishEvent('content-1', 1),
            $this->publishEvent('content-2', 1),
            $this->unpublishEvent('content-1', 2),
        ]);

        $advisoryLocks = DB::scalar("select count(*) from pg_locks where locktype = 'advisory' and pid = pg_backend_pid()");

        $this->assertSame(2, $advisoryLocks);
    }

    public function test_logs_each_event_and_the_batch_summary_without_the_payload(): void
    {
        $log = $this->captureCmsLog();

        $this->process([
            $this->publishEvent('content-1', 2, title: 'Confidential draft'),
            $this->publishEvent('content-1', 2, title: 'Confidential draft'),
        ]);

        $records = $log->getRecords();
        $this->assertSame(['cms.event.applied', 'cms.event.ignored', 'cms.batch.processed'], array_map(
            static fn ($record): string => $record->message,
            $records,
        ));
        $this->assertSame([
            'batch_id' => self::BATCH_ID,
            'external_id' => 'content-1',
            'type' => 'publish',
            'version' => 2,
            'outcome' => 'applied',
        ], $records[0]->context);
        $this->assertSame([
            'batch_id' => self::BATCH_ID,
            'external_id' => 'content-1',
            'type' => 'publish',
            'version' => 2,
            'previous_version' => 2,
            'outcome' => 'ignored',
            'reason' => 'duplicate',
        ], $records[1]->context);
        $this->assertSame(
            ['batch_id', 'event_count', 'applied_count', 'ignored_count', 'duration_ms'],
            array_keys($records[2]->context),
        );
        $this->assertSame([self::BATCH_ID, 2, 1, 1], array_slice(array_values($records[2]->context), 0, 4));
        $this->assertStringNotContainsString('Confidential draft', (string) json_encode($records));
    }

    public function test_logs_a_failed_batch_without_the_exception_message_or_any_event_outcome(): void
    {
        $log = $this->captureCmsLog();
        $this->failWhenCreating('content-2', message: 'Failing row contains Confidential draft');

        $this->processFailingBatch([
            $this->publishEvent('content-1', 1),
            $this->publishEvent('content-2', 1, title: 'Confidential draft'),
        ]);

        $records = $log->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('cms.batch.failed', $records[0]->message);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertSame(
            ['batch_id', 'event_count', 'exception_class', 'exception_code', 'origin'],
            array_keys($records[0]->context),
        );
        $this->assertSame([self::BATCH_ID, 2, RuntimeException::class], array_slice(array_values($records[0]->context), 0, 3));
        $this->assertStringNotContainsString('Confidential draft', (string) json_encode($records));
    }

    /**
     * @param  list<CmsEvent>  $events
     */
    private function process(array $events): BatchResult
    {
        return $this->app->make(CmsEventProcessor::class)->process(self::BATCH_ID, $events);
    }

    /**
     * @param  list<CmsEvent>  $events
     */
    private function processFailingBatch(array $events): CmsBatchProcessingFailed
    {
        try {
            $this->process($events);
        } catch (CmsBatchProcessingFailed $exception) {
            return $exception;
        }

        $this->fail('The failing batch was processed.');
    }

    /**
     * @return list<string>
     */
    private function outcomes(BatchResult $batch): array
    {
        return array_map(
            static fn (EventResult $event): string => implode(':', array_filter([$event->outcome->value, $event->reason?->value])),
            $batch->events,
        );
    }

    private function content(string $externalId): Content
    {
        return Content::query()->where('external_id', $externalId)->sole();
    }

    private function failWhenCreating(string $externalId, string $message = 'Simulated storage failure.'): void
    {
        Content::creating(static function (Content $content) use ($externalId, $message): void {
            if ($content->external_id === $externalId) {
                throw new RuntimeException($message);
            }
        });
    }

    private function publishEvent(string $externalId, int $version, ?string $title = null, string $timestamp = self::DEFAULT_TIMESTAMP): CmsEvent
    {
        return $this->makeEvent(CmsEventType::Publish, $externalId, $version, $title, $timestamp);
    }

    private function unpublishEvent(string $externalId, int $version): CmsEvent
    {
        return $this->makeEvent(CmsEventType::Unpublish, $externalId, $version);
    }

    private function deleteEvent(string $externalId, int $version): CmsEvent
    {
        return $this->makeEvent(CmsEventType::Delete, $externalId, $version);
    }

    private function makeEvent(
        CmsEventType $type,
        string $externalId,
        int $version,
        ?string $title = null,
        string $timestamp = self::DEFAULT_TIMESTAMP,
    ): CmsEvent {
        return new CmsEvent(
            type: $type,
            externalId: $externalId,
            version: $version,
            timestamp: CarbonImmutable::parse($timestamp),
            payload: $type->carriesPayload() ? (object) ['title' => $title ?? "Version {$version}"] : null,
        );
    }
}
