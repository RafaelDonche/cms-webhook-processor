<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\ContentLockManager;
use App\Data\BatchResult;
use App\Data\CmsEvent;
use App\Data\EventResult;
use App\Exceptions\CmsBatchProcessingFailed;
use App\Logging\CmsWebhookLogger;
use App\Models\Content;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final readonly class CmsEventProcessor
{
    private const int NANOSECONDS_PER_MILLISECOND = 1_000_000;

    public function __construct(
        private ConnectionInterface $connection,
        private ContentLockManager $locks,
        private CmsWebhookLogger $logger,
    ) {}

    /**
     * @param  list<CmsEvent>  $events
     *
     * @throws CmsBatchProcessingFailed
     */
    public function process(string $batchId, array $events): BatchResult
    {
        $startedAt = hrtime(true);

        try {
            $results = $this->connection->transaction(fn (): array => $this->applyInReceivedOrder($events));
        } catch (Throwable $exception) {
            $this->logger->batchFailed($batchId, count($events), $exception);

            throw CmsBatchProcessingFailed::forBatch($batchId);
        }

        $batch = new BatchResult($batchId, $results);

        foreach ($batch->events as $event) {
            $this->logger->eventProcessed($batchId, $event);
        }

        $this->logger->batchProcessed($batch, $this->millisecondsSince($startedAt));

        return $batch;
    }

    /**
     * @param  list<CmsEvent>  $events
     * @return list<EventResult>
     */
    private function applyInReceivedOrder(array $events): array
    {
        $externalIds = array_values(array_unique(array_map(
            static fn (CmsEvent $event): string => $event->externalId,
            $events,
        )));

        $this->locks->lockForCurrentTransaction($externalIds);

        // Read only after locking: under READ COMMITTED this sees whatever the batches we waited for committed.
        $contents = Content::query()->whereIn('external_id', $externalIds)->get()->keyBy('external_id')->all();

        $results = [];

        foreach ($events as $event) {
            $contents[$event->externalId] ??= Content::forExternalId($event->externalId);
            $results[] = $this->apply($event, $contents[$event->externalId]);
        }

        return $results;
    }

    private function apply(CmsEvent $event, Content $content): EventResult
    {
        if (! $content->acceptsVersion($event->version)) {
            return EventResult::ignored($event, $content->version);
        }

        $previousVersion = $content->exists ? $content->version : null;

        $content->applyEvent($event);
        $content->save();

        return EventResult::applied($event, $previousVersion);
    }

    private function millisecondsSince(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / self::NANOSECONDS_PER_MILLISECOND, 2);
    }
}
