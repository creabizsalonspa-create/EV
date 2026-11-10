<?php

use App\Http\Controllers\VehicleDriverPreferencesController;
use Illuminate\Support\Facades\Route;

Route::get('/vehicles/{vehicle}/driver-preferences', [VehicleDriverPreferencesController::class, 'show'])
    ->whereNumber('vehicle');
Route::put('/vehicles/{vehicle}/driver-preferences', [VehicleDriverPreferencesController::class, 'save'])
    ->whereNumber('vehicle');
