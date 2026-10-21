<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CmsEventType;
use App\Enums\ContentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CmsEventTypeTest extends TestCase
{
    #[DataProvider('resultingStatuses')]
    public function test_each_event_type_leads_to_its_content_status(CmsEventType $type, ContentStatus $expected): void
    {
        $this->assertSame($expected, $type->resultingStatus());
    }

    /**
     * @return array<string, array{CmsEventType, ContentStatus}>
     */
    public static function resultingStatuses(): array
    {
        return [
            'publish' => [CmsEventType::Publish, ContentStatus::Published],
            'unpublish' => [CmsEventType::Unpublish, ContentStatus::Unpublished],
            'delete' => [CmsEventType::Delete, ContentStatus::Deleted],
        ];
    }

    #[DataProvider('payloadCarriers')]
    public function test_only_delete_events_do_not_carry_a_payload(CmsEventType $type, bool $expected): void
    {
        $this->assertSame($expected, $type->carriesPayload());
    }

    /**
     * @return array<string, array{CmsEventType, bool}>
     */
    public static function payloadCarriers(): array
    {
        return [
            'publish' => [CmsEventType::Publish, true],
            'unpublish' => [CmsEventType::Unpublish, true],
            'delete' => [CmsEventType::Delete, false],
        ];
    }
}
