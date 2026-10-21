<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ReceiveCmsEventsRequest;
use Illuminate\Http\JsonResponse;

final class ReceiveCmsEventsController extends Controller
{
    public function __invoke(ReceiveCmsEventsRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ['received' => count($request->toEvents())]]);
    }
}
