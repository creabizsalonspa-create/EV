<?php

use App\Http\Controllers\RoamingOrderController as R;
use Illuminate\Support\Facades\Route;

Route::get('/sandbox-capabilities', [R::class, 'capabilities']);
Route::get('/locations/{location}/sandbox-offers', [R::class, 'offers'])->whereNumber('location');
Route::post('/locations/{location}/sandbox-orders', [R::class, 'create'])->whereNumber('location');
Route::get('/sandbox-orders', [R::class, 'index']);
Route::get('/sandbox-orders/{order}', [R::class, 'show'])->where('order', '[0-9]{1,18}');
Route::post('/sandbox-orders/{order}/reconcile', [R::class, 'reconcile'])->where('order', '[0-9]{1,18}');
Route::post('/sandbox-orders/{order}/stop', [R::class, 'stop'])->where('order', '[0-9]{1,18}');
Route::delete('/sandbox-orders/{order}/authority', [R::class, 'revoke'])->where('order', '[0-9]{1,18}');
