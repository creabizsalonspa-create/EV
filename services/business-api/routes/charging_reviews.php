<?php

use App\Http\Controllers\ChargingReviewController as C;
use Illuminate\Support\Facades\Route;

Route::get('/mine', [C::class, 'mine']);
Route::get('/eligible', [C::class, 'eligible']);
Route::get('/operations', [C::class, 'operations']);
Route::post('/sessions/{session}', [C::class, 'create'])->whereNumber('session');
Route::put('/{review}', [C::class, 'revise'])->whereNumber('review');
Route::delete('/{review}', [C::class, 'withdraw'])->whereNumber('review');
Route::get('/{review}/history', [C::class, 'history'])->whereNumber('review');
Route::post('/{review}/moderate', [C::class, 'moderate'])->whereNumber('review');
