<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Http\Requests\Cms\ReceiveCmsEventsRequest;
use App\Http\Resources\Cms\BatchResultResource;
use App\Services\Cms\CmsEventProcessor;
use Illuminate\Container\Attributes\Context;

final class ReceiveCmsEventsController extends Controller
{
    public function __invoke(
        ReceiveCmsEventsRequest $request,
        CmsEventProcessor $processor,
        #[Context(AssignRequestId::CONTEXT_KEY)]
        string $requestId,
    ): BatchResultResource {
        return new BatchResultResource($processor->process($requestId, $request->toEvents()));
    }
}
