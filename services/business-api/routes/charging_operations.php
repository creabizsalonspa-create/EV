<?php

use App\Http\Controllers\ChargingOperationsController as O;
use Illuminate\Support\Facades\Route;

Route::get('/charging-operations/{command}/case', [O::class, 'show']);
Route::get('/charging-operations', [O::class, 'index']);
Route::post('/charging-operations/{command}/case', [O::class, 'open']);
Route::patch('/charging-operations/{command}/case', [O::class, 'review']);
Route::post('/charging-operations/{command}/inspect-source', [O::class, 'inspect']);
