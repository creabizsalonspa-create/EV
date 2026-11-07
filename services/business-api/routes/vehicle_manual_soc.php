<?php

use App\Http\Controllers\VehicleManualSocController as C;
use Illuminate\Support\Facades\Route;

Route::get('/vehicles/{vehicle}/manual-soc', [C::class, 'show'])->whereNumber('vehicle');
Route::post('/vehicles/{vehicle}/manual-soc', [C::class, 'save'])->whereNumber('vehicle');
Route::get('/vehicles/{vehicle}/manual-soc/history', [C::class, 'history'])->whereNumber('vehicle');
