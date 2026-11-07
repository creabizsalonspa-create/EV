<?php

use App\Http\Controllers\StationFavoriteController as F;
use Illuminate\Support\Facades\Route;

Route::get('/favorites', [F::class, 'index']);
Route::get('/favorites/status', [F::class, 'status']);
Route::put('/favorites/{station}', [F::class, 'save'])->whereNumber('station');
Route::delete('/favorites/{station}', [F::class, 'remove'])->whereNumber('station');
