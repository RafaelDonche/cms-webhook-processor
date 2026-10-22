<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListContentsRequest;
use App\Http\Resources\Api\V1\ContentResource;
use App\Models\Content;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ContentController extends Controller
{
    public function index(ListContentsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $disabled = $request->disabledFilter();

        $contents = Content::query()
            ->visibleTo($user)
            ->when($request->statusFilter(), fn (Builder $query, ContentStatus $status) => $query->where('status', $status))
            ->when($disabled !== null, fn (Builder $query) => $query->disabled($disabled === true))
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ContentResource::collection($contents);
    }

    public function show(Content $content): ContentResource
    {
        return new ContentResource($content);
    }
}
