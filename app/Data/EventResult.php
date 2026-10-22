<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CmsEventType;
use App\Enums\EventOutcome;
use App\Enums\IgnoreReason;

final readonly class EventResult
{
    private function __construct(
        public string $externalId,
        public CmsEventType $type,
        public int $version,
        public ?int $previousVersion,
        public EventOutcome $outcome,
        public ?IgnoreReason $reason,
    ) {}

    public static function applied(CmsEvent $event, ?int $previousVersion): self
    {
        return new self($event->externalId, $event->type, $event->version, $previousVersion, EventOutcome::Applied, null);
    }

    public static function ignored(CmsEvent $event, int $acceptedVersion): self
    {
        return new self(
            $event->externalId,
            $event->type,
            $event->version,
            $acceptedVersion,
            EventOutcome::Ignored,
            IgnoreReason::forVersions($acceptedVersion, $event->version),
        );
    }
}
