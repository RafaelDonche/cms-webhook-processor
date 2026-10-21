<?php

declare(strict_types=1);

namespace App\Logging;

use App\Enums\WebhookAuthFailure;
use Illuminate\Container\Attributes\Log;
use Psr\Log\LoggerInterface;

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
}
