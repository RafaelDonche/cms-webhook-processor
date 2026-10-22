<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\ContentDisablementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->name('v1.')->group(function (): void {
    Route::apiResource('contents', ContentController::class)->only(['index', 'show']);

    Route::controller(ContentDisablementController::class)
        ->prefix('contents/{content}/disabled')
        ->name('contents.disabled.')
        ->middleware('can:manageDisablement,content')
        ->group(function (): void {
            Route::put('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
        });
});
