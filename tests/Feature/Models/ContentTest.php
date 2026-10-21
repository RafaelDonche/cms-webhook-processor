<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Data\CmsEvent;
use App\Enums\CmsEventType;
use App\Enums\ContentStatus;
use App\Exceptions\OutdatedContentEvent;
use App\Models\Content;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

final class ContentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_applying_a_publish_event_to_new_content_persists_the_cms_state(): void
    {
        $content = Content::forExternalId('content-123');

        $content->applyEvent($this->makeEvent(CmsEventType::Publish, 'content-123', 7, (object) ['title' => 'Example']));
        $content->save();

        $content->refresh();
        $this->assertSame(7, $content->version);
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertEquals((object) ['title' => 'Example'], $content->payload);
        $this->assertSame('2026-01-01T10:00:00+00:00', $content->source_updated_at?->toAtomString());
    }

    public function test_applying_an_unpublish_event_keeps_the_supplied_content(): void
    {
        $content = Content::factory()->published()->create(['external_id' => 'content-123', 'version' => 3]);

        $content->applyEvent($this->makeEvent(CmsEventType::Unpublish, 'content-123', 4, (object) ['title' => 'Version 4']));
        $content->save();

        $content->refresh();
        $this->assertSame(4, $content->version);
        $this->assertSame(ContentStatus::Unpublished, $content->status);
        $this->assertEquals((object) ['title' => 'Version 4'], $content->payload);
    }

    public function test_applying_a_delete_event_removes_the_payload_and_keeps_the_version(): void
    {
        $content = Content::factory()->published()->create(['external_id' => 'content-123', 'version' => 3]);

        $content->applyEvent($this->makeEvent(CmsEventType::Delete, 'content-123', 5));
        $content->save();

        $content->refresh();
        $this->assertSame(5, $content->version);
        $this->assertSame(ContentStatus::Deleted, $content->status);
        $this->assertNull($content->payload);
    }

    #[DataProvider('versionsNotNewerThanThree')]
    public function test_rejects_events_that_do_not_increase_the_version(int $version): void
    {
        $content = Content::factory()->published()->create(['external_id' => 'content-123', 'version' => 3]);

        try {
            $content->applyEvent($this->makeEvent(CmsEventType::Unpublish, 'content-123', $version, (object) ['title' => 'Stale']));
            $this->fail('An outdated event was applied.');
        } catch (OutdatedContentEvent) {
            $this->assertFalse($content->isDirty());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function versionsNotNewerThanThree(): array
    {
        return [
            'same version' => [3],
            'older version' => [2],
        ];
    }

    public function test_rejects_events_addressed_to_another_content(): void
    {
        $content = Content::forExternalId('content-123');

        $this->expectException(InvalidArgumentException::class);

        $content->applyEvent($this->makeEvent(CmsEventType::Publish, 'content-456', 1, (object) ['title' => 'Other']));
    }

    public function test_applying_events_preserves_the_local_disable_override(): void
    {
        $admin = User::factory()->admin()->create();
        $content = Content::factory()->published()->disabled($admin)->create(['external_id' => 'content-123', 'version' => 1]);
        $disabledAt = $content->disabled_at?->toAtomString();

        $content->applyEvent($this->makeEvent(CmsEventType::Unpublish, 'content-123', 2, (object) ['title' => 'Unpublished']));
        $content->save();
        $content->applyEvent($this->makeEvent(CmsEventType::Publish, 'content-123', 3, (object) ['title' => 'Republished']));
        $content->save();

        $content->refresh();
        $this->assertSame($disabledAt, $content->disabled_at?->toAtomString());
        $this->assertSame($admin->id, $content->disabled_by);
    }

    public function test_payload_keeps_empty_objects_distinct_from_empty_lists(): void
    {
        $payload = json_decode('{"title": "Example", "meta": {}, "tags": []}', flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload);
        $content = Content::forExternalId('content-123');

        $content->applyEvent($this->makeEvent(CmsEventType::Publish, 'content-123', 1, $payload));
        $content->save();

        $content->refresh();
        $this->assertInstanceOf(stdClass::class, $content->payload?->meta);
        $this->assertSame([], $content->payload->tags);
    }

    public function test_disabling_records_the_admin_and_the_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01T12:00:00Z'));
        $admin = User::factory()->admin()->create();
        $content = Content::factory()->published()->create();

        $content->disable($admin);
        $content->save();

        $content->refresh();
        $this->assertTrue($content->isDisabled());
        $this->assertSame('2026-02-01T12:00:00+00:00', $content->disabled_at?->toAtomString());
        $this->assertSame($admin->id, $content->disabled_by);
    }

    public function test_disabling_already_disabled_content_keeps_the_original_override(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01T12:00:00Z'));
        $firstAdmin = User::factory()->admin()->create();
        $secondAdmin = User::factory()->admin()->create();
        $content = Content::factory()->published()->disabled($firstAdmin)->create();
        $this->travelTo(CarbonImmutable::parse('2026-02-02T12:00:00Z'));

        $content->disable($secondAdmin);
        $content->save();

        $content->refresh();
        $this->assertSame('2026-02-01T12:00:00+00:00', $content->disabled_at?->toAtomString());
        $this->assertSame($firstAdmin->id, $content->disabled_by);
    }

    public function test_enabling_clears_the_override(): void
    {
        $content = Content::factory()->published()->disabled()->create();

        $content->enable();
        $content->save();

        $content->refresh();
        $this->assertFalse($content->isDisabled());
        $this->assertNull($content->disabled_by);
    }

    public function test_content_stays_disabled_when_the_disabling_admin_is_removed(): void
    {
        $admin = User::factory()->admin()->create();
        $content = Content::factory()->published()->disabled($admin)->create();

        $admin->delete();

        $content->refresh();
        $this->assertTrue($content->isDisabled());
        $this->assertNull($content->disabled_by);
    }

    public function test_users_only_see_published_content_that_is_not_disabled(): void
    {
        $visible = Content::factory()->published()->create();
        Content::factory()->published()->disabled()->create();
        Content::factory()->unpublished()->create();
        Content::factory()->deleted()->create();

        $ids = Content::query()->visibleTo(User::factory()->create())->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
    }

    public function test_admins_see_published_and_unpublished_content_including_disabled(): void
    {
        $published = Content::factory()->published()->create();
        $disabled = Content::factory()->published()->disabled()->create();
        $unpublished = Content::factory()->unpublished()->create();
        Content::factory()->deleted()->create();

        $ids = Content::query()->visibleTo(User::factory()->admin()->create())->orderBy('id')->pluck('id')->all();

        $this->assertSame([$published->id, $disabled->id, $unpublished->id], $ids);
    }

    public function test_database_rejects_non_positive_versions(): void
    {
        $this->expectException(QueryException::class);

        Content::factory()->create(['version' => 0]);
    }

    public function test_database_rejects_duplicate_external_ids(): void
    {
        Content::factory()->create(['external_id' => 'content-123']);

        $this->expectException(QueryException::class);

        Content::factory()->create(['external_id' => 'content-123']);
    }

    #[DataProvider('payloadsInconsistentWithStatus')]
    public function test_database_rejects_payloads_inconsistent_with_the_status(ContentStatus $status, ?stdClass $payload): void
    {
        $this->expectException(QueryException::class);

        Content::factory()->create(['status' => $status, 'payload' => $payload]);
    }

    /**
     * @return array<string, array{ContentStatus, ?stdClass}>
     */
    public static function payloadsInconsistentWithStatus(): array
    {
        return [
            'published without payload' => [ContentStatus::Published, null],
            'unpublished without payload' => [ContentStatus::Unpublished, null],
            'deleted with payload' => [ContentStatus::Deleted, (object) ['title' => 'Leftover']],
        ];
    }

    private function makeEvent(CmsEventType $type, string $externalId, int $version, ?stdClass $payload = null): CmsEvent
    {
        return new CmsEvent(
            type: $type,
            externalId: $externalId,
            version: $version,
            timestamp: CarbonImmutable::parse('2026-01-01T10:00:00Z'),
            payload: $payload,
        );
    }
}
