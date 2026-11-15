<?php

use App\Http\Controllers\OcpiCatalogObservationsController;
use App\Http\Controllers\OcpiController;
use App\Http\Controllers\OcpiCredentialsController;
use App\Http\Controllers\OcpiProvisioningController;
use App\Http\Controllers\OcpiSyncPlanController;
use App\Http\Controllers\RoamingMeterHistoryController;
use App\Http\Controllers\RoamingOrderController;
use Illuminate\Support\Facades\Route;

// Shared canonical operations; the parent supplies web or native authentication.
Route::get('/providers/{provider}/sandbox-orders', [RoamingOrderController::class, 'operations']);
Route::get('/providers/{provider}/sandbox-orders/{order}/meter-history', [RoamingMeterHistoryController::class, 'operator'])->where('provider', '[0-9]{1,18}')->where('order', '[0-9]{1,18}');
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

Route::get('/providers/{provider}/sync-plans', [OcpiSyncPlanController::class, 'show']);
Route::put('/providers/{provider}/sync-plans/{module}', [OcpiSyncPlanController::class, 'update'])->whereIn('module', ['locations', 'tariffs', 'sessions', 'cdrs']);

Route::get('/providers/{provider}/catalog-observations', [OcpiCatalogObservationsController::class, 'show'])->where('provider', '[0-9]{1,18}');
