<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\CmsEvent;
use App\Enums\CmsEventType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CmsEventTest extends TestCase
{
    public function test_accepts_one_as_the_lowest_version(): void
    {
        $event = $this->makeEvent(CmsEventType::Publish, version: 1, payload: (object) ['title' => 'First']);

        $this->assertSame(1, $event->version);
    }

    #[DataProvider('nonPositiveVersions')]
    public function test_rejects_non_positive_versions(int $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeEvent(CmsEventType::Publish, version: $version, payload: (object) ['title' => 'Invalid']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveVersions(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    #[DataProvider('eventTypesRequiringPayload')]
    public function test_rejects_events_that_require_a_payload_without_one(CmsEventType $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeEvent($type, version: 1, payload: null);
    }

    /**
     * @return array<string, array{CmsEventType}>
     */
    public static function eventTypesRequiringPayload(): array
    {
        return [
            'publish' => [CmsEventType::Publish],
            'unpublish' => [CmsEventType::Unpublish],
        ];
    }

    public function test_discards_the_payload_of_delete_events(): void
    {
        $event = $this->makeEvent(CmsEventType::Delete, version: 5, payload: (object) ['title' => 'Ignored']);

        $this->assertNull($event->payload);
    }

    private function makeEvent(CmsEventType $type, int $version, ?stdClass $payload): CmsEvent
    {
        return new CmsEvent(
            type: $type,
            externalId: 'content-123',
            version: $version,
            timestamp: CarbonImmutable::parse('2026-01-01T10:00:00Z'),
            payload: $payload,
        );
    }
}
