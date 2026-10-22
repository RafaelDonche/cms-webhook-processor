<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContentResource;
use App\Logging\ContentDisablementLogger;
use App\Models\Content;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

final class ContentDisablementController extends Controller
{
    public function __construct(
        private readonly ContentDisablementLogger $logger,
    ) {}

    public function update(Content $content, #[CurrentUser] User $admin): ContentResource
    {
        $content->disable($admin);
        $content->save();

        if ($content->wasChanged('disabled_at')) {
            $this->logger->disabled($content, $admin);
        }

        return new ContentResource($content);
    }

    public function destroy(Content $content, #[CurrentUser] User $admin): ContentResource
    {
        $content->enable();
        $content->save();

        if ($content->wasChanged('disabled_at')) {
            $this->logger->enabled($content, $admin);
        }

        return new ContentResource($content);
    }
}
