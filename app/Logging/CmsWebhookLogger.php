<?php

declare(strict_types=1);

namespace App\Logging;

use App\Data\BatchResult;
use App\Data\EventResult;
use App\Enums\WebhookAuthFailure;
use Illuminate\Container\Attributes\Log;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class CmsWebhookLogger
{
    public function __construct(
        #[Log('cms')]
        private LoggerInterface $logger,
    ) {}

    public function authenticationFailed(WebhookAuthFailure $failure, ?string $clientIp): void
    {
        $this->logger->log($failure->logLevel(), 'cms.auth.failed', [
            'reason' => $failure->value,
            'client_ip' => $clientIp,
        ]);
    }

    /**
     * @param  list<string>  $invalidFields
     */
    public function batchRejected(int $eventCount, array $invalidFields): void
    {
        $this->logger->warning('cms.batch.rejected', [
            'event_count' => $eventCount,
            'invalid_fields' => $invalidFields,
        ]);
    }

    public function eventProcessed(string $batchId, EventResult $event): void
    {
        $this->logger->info("cms.event.{$event->outcome->value}", array_filter([
            'batch_id' => $batchId,
            'external_id' => $event->externalId,
            'type' => $event->type->value,
            'version' => $event->version,
            'previous_version' => $event->previousVersion,
            'outcome' => $event->outcome->value,
            'reason' => $event->reason?->value,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function batchProcessed(BatchResult $batch, float $durationMs): void
    {
        $this->logger->info('cms.batch.processed', [
            'batch_id' => $batch->batchId,
            'event_count' => count($batch->events),
            'applied_count' => $batch->appliedCount(),
            'ignored_count' => $batch->ignoredCount(),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * The exception message is left out on purpose: database errors quote the failing row, CMS content included.
     */
    public function batchFailed(string $batchId, int $eventCount, Throwable $exception): void
    {
        $this->logger->error('cms.batch.failed', [
            'batch_id' => $batchId,
            'event_count' => $eventCount,
            'exception_class' => $exception::class,
            'exception_code' => $exception->getCode(),
            'origin' => "{$exception->getFile()}:{$exception->getLine()}",
        ]);
    }
}
