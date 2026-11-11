<?php

use App\Http\Controllers\ChargingReviewController as C;
use Illuminate\Support\Facades\Route;

Route::get('/mine', [C::class, 'mine']);
Route::get('/eligible', [C::class, 'eligible']);
Route::get('/operations', [C::class, 'operations']);
Route::post('/sessions/{session}', [C::class, 'create'])->where('session', '[0-9]{1,18}');
Route::put('/{review}', [C::class, 'revise'])->where('review', '[0-9]{1,18}');
Route::delete('/{review}', [C::class, 'withdraw'])->where('review', '[0-9]{1,18}');
Route::get('/{review}/history', [C::class, 'history'])->where('review', '[0-9]{1,18}');
Route::post('/{review}/moderate', [C::class, 'moderate'])->where('review', '[0-9]{1,18}');
