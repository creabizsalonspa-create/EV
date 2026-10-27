<?php

use App\Http\Controllers\OcpiController;
use Illuminate\Support\Facades\Route;

Route::get('/api/roaming/locations', [OcpiController::class, 'locations'])->middleware('throttle:catalog');
Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/ocpi')->group(function () {
    Route::get('/providers', [OcpiController::class, 'providers']);
    Route::post('/providers', [OcpiController::class, 'create']);
    Route::patch('/providers/{provider}/authorization', [OcpiController::class, 'authorization']);
    Route::post('/providers/{provider}/negotiate', [OcpiController::class, 'negotiate']);
    Route::post('/providers/{provider}/sync/{module}', [OcpiController::class, 'sync']);
    Route::get('/providers/{provider}/records/{module}', [OcpiController::class, 'records']);
});
