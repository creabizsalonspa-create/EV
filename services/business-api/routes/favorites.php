<?php

use App\Http\Controllers\StationFavoriteController as F;
use Illuminate\Support\Facades\Route;

Route::get('/favorites', [F::class, 'index']);
Route::get('/favorites/status', [F::class, 'status']);
Route::put('/favorites/{station}', [F::class, 'save'])->where('station', '[0-9]{1,18}');
Route::delete('/favorites/{station}', [F::class, 'remove'])->where('station', '[0-9]{1,18}');
