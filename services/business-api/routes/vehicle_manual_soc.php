<?php

use App\Http\Controllers\VehicleManualSocController as C;
use Illuminate\Support\Facades\Route;

Route::get('/vehicles/{vehicle}/manual-soc', [C::class, 'show'])->where('vehicle', '[0-9]{1,18}');
Route::post('/vehicles/{vehicle}/manual-soc', [C::class, 'save'])->where('vehicle', '[0-9]{1,18}');
Route::get('/vehicles/{vehicle}/manual-soc/history', [C::class, 'history'])->where('vehicle', '[0-9]{1,18}');
