<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContentResource;
use App\Logging\ContentDisablementLogger;
use App\Models\Content;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\ConnectionInterface;

final class ContentDisablementController extends Controller
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ContentDisablementLogger $logger,
    ) {}

    public function update(Content $content, #[CurrentUser] User $admin): ContentResource
    {
        $content = $this->changeOverride($content, static fn (Content $locked) => $locked->disable($admin));

        if ($content->wasChanged('disabled_at')) {
            $this->logger->disabled($content, $admin);
        }

        return new ContentResource($content);
    }

    public function destroy(Content $content, #[CurrentUser] User $admin): ContentResource
    {
        $content = $this->changeOverride($content, static fn (Content $locked) => $locked->enable());

        if ($content->wasChanged('disabled_at')) {
            $this->logger->enabled($content, $admin);
        }

        return new ContentResource($content);
    }

    /**
     * The row is re-read under a lock so that concurrent requests apply one after the other: a second disable
     * sees the first one, keeps its original override and does not log the change again.
     *
     * @param  Closure(Content): void  $change
     */
    private function changeOverride(Content $content, Closure $change): Content
    {
        return $this->connection->transaction(static function () use ($content, $change): Content {
            $locked = Content::query()->lockForUpdate()->findOrFail($content->id);
            $change($locked);
            $locked->save();

            return $locked;
        });
    }
}
