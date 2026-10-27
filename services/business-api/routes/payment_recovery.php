<?php

use App\Http\Controllers\PaymentRecoveryController as P;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/payment-recovery', [P::class, 'index']);
Route::get('/commercial/payment-recovery/{intent}/events', [P::class, 'events'])->whereNumber('intent');
Route::post('/commercial/payment-recovery/{intent}/reconcile', [P::class, 'reconcile'])->whereNumber('intent');
