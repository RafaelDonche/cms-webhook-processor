<?php

declare(strict_types=1);

namespace App\Logging;

use App\Models\Content;
use App\Models\User;
use Psr\Log\LoggerInterface;

final readonly class ContentDisablementLogger
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function disabled(Content $content, User $admin): void
    {
        $this->logger->info('content.disabled', $this->context($content, $admin));
    }

    public function enabled(Content $content, User $admin): void
    {
        $this->logger->info('content.enabled', $this->context($content, $admin));
    }

    /**
     * @return array{external_id: string, admin_id: int}
     */
    private function context(Content $content, User $admin): array
    {
        return [
            'external_id' => $content->external_id,
            'admin_id' => $admin->id,
        ];
    }
}
