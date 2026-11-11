<?php

use App\Http\Controllers\VehicleDriverPreferencesController;
use Illuminate\Support\Facades\Route;

Route::get('/vehicles/{vehicle}/driver-preferences', [VehicleDriverPreferencesController::class, 'show'])
    ->where('vehicle', '[0-9]{1,18}');
Route::put('/vehicles/{vehicle}/driver-preferences', [VehicleDriverPreferencesController::class, 'save'])
    ->where('vehicle', '[0-9]{1,18}');
