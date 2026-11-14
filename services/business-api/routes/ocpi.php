<?php

use App\Http\Controllers\OcpiController;
use App\Http\Controllers\OcpiCredentialsController;
use App\Http\Controllers\OcpiProvisioningController;
use App\Http\Controllers\PartnerLocationDiscoveryController;
use App\Http\Controllers\PartnerLocationFavoriteController;
use App\Http\Controllers\RoamingOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/api/roaming/locations', [PartnerLocationDiscoveryController::class, 'index'])->middleware('throttle:catalog');
Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/ocpi')->group(function () {
    Route::get('/providers/{provider}/sandbox-orders', [RoamingOrderController::class, 'operations']);
    Route::get('/connection-options', [OcpiProvisioningController::class, 'connections']);
    Route::get('/owner-options', [OcpiProvisioningController::class, 'owners']);
    Route::get('/providers/{provider}/credentials', [OcpiCredentialsController::class, 'status']);
    Route::post('/providers/{provider}/credentials', [OcpiCredentialsController::class, 'exchange']);
    Route::get('/providers', [OcpiController::class, 'providers']);
    Route::post('/providers', [OcpiController::class, 'create']);
    Route::patch('/providers/{provider}/authorization', [OcpiController::class, 'authorization']);
    Route::post('/providers/{provider}/negotiate', [OcpiController::class, 'negotiate']);
    Route::post('/providers/{provider}/sync/{module}', [OcpiController::class, 'sync']);
    Route::get('/providers/{provider}/billing-reconciliation/{format}', [OcpiController::class, 'billingExport'])->whereIn('format', ['csv', 'pdf']);
    Route::get('/providers/{provider}/billing-reconciliation', [OcpiController::class, 'billingReconciliation']);
    Route::get('/providers/{provider}/records/{module}', [OcpiController::class, 'records']);
});

Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/roaming/favorites')->group(function () {
    Route::get('/', [PartnerLocationFavoriteController::class, 'index']);
    Route::post('/status', [PartnerLocationFavoriteController::class, 'status']);
    Route::put('/{location}', [PartnerLocationFavoriteController::class, 'save']);
    Route::delete('/{location}', [PartnerLocationFavoriteController::class, 'remove']);
});

Route::middleware(['auth', 'throttle:platform-api'])->prefix('/api/roaming')->group(__DIR__.'/roaming_orders.php');
