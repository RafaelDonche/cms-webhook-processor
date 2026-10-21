<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Logging\CmsWebhookLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

trait CapturesCmsLog
{
    protected function captureCmsLog(): TestHandler
    {
        $handler = new TestHandler;

        $this->app->instance(CmsWebhookLogger::class, new CmsWebhookLogger(new Logger('cms', [$handler])));

        return $handler;
    }
}
