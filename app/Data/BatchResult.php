<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\EventOutcome;

final readonly class BatchResult
{
    /**
     * @param  list<EventResult>  $events
     */
    public function __construct(
        public string $batchId,
        public array $events,
    ) {}

    public function appliedCount(): int
    {
        return $this->countWithOutcome(EventOutcome::Applied);
    }

    public function ignoredCount(): int
    {
        return $this->countWithOutcome(EventOutcome::Ignored);
    }

    private function countWithOutcome(EventOutcome $outcome): int
    {
        return count(array_filter($this->events, static fn (EventResult $event): bool => $event->outcome === $outcome));
    }
}
