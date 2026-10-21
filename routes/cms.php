<?php

declare(strict_types=1);

use App\Http\Controllers\Cms\ReceiveCmsEventsController;
use Illuminate\Support\Facades\Route;

Route::post('events', ReceiveCmsEventsController::class)->name('events.store');
