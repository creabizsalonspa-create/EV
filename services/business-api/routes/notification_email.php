<?php

use App\Http\Controllers\NotificationEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/email', [NotificationEmailController::class, 'show'])->middleware('throttle:notification-read');
Route::put('/email', [NotificationEmailController::class, 'update'])->middleware('throttle:notification-write');
Route::get('/email/history', [NotificationEmailController::class, 'history'])->middleware('throttle:notification-read');
