<?php

use App\Http\Controllers\PartnerLocationDiscoveryController;
use App\Http\Controllers\PartnerLocationFavoriteController;
use Illuminate\Support\Facades\Route;

Route::get('/api/roaming/locations', [PartnerLocationDiscoveryController::class, 'index'])->middleware('throttle:catalog');
Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/ocpi')->group(__DIR__.'/ocpi_operations.php');

Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/roaming/favorites')->group(function () {
    Route::get('/', [PartnerLocationFavoriteController::class, 'index']);
    Route::post('/status', [PartnerLocationFavoriteController::class, 'status']);
    Route::put('/{location}', [PartnerLocationFavoriteController::class, 'save']);
    Route::delete('/{location}', [PartnerLocationFavoriteController::class, 'remove']);
});

Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/roaming')->group(__DIR__.'/roaming_orders.php');
