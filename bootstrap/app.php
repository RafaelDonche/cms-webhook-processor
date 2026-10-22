<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateCmsWebhook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        then: function (): void {
            Route::middleware(['throttle:cms-webhook', 'auth.cms'])
                ->prefix('cms')
                ->name('cms.')
                ->group(base_path('routes/cms.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['auth.cms' => AuthenticateCmsWebhook::class]);
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);

        $exceptions->render(fn (NotFoundHttpException $exception): ?JsonResponse => $exception->getPrevious() instanceof ModelNotFoundException
            ? new JsonResponse(['message' => 'Resource not found.'], Response::HTTP_NOT_FOUND)
            : null);
    })->create();
