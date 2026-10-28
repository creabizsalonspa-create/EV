<?php

use App\Http\Controllers\RecurringPaymentController as R;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/payment-methods', [R::class, 'methods']);
Route::get('/commercial/payment-enrollments', [R::class, 'enrollments']);
Route::post('/commercial/payment-enrollments', [R::class, 'begin']);
Route::post('/commercial/payment-enrollments/{enrollment}/inspect', [R::class, 'inspect'])->whereNumber('enrollment');
Route::post('/commercial/payment-methods/{method}/default', [R::class, 'defaultMethod'])->whereNumber('method');
Route::delete('/commercial/payment-methods/{method}', [R::class, 'revoke'])->whereNumber('method');
Route::post('/commercial/payment-methods/{method}/detach', [R::class, 'detach'])->whereNumber('method');
Route::get('/commercial/recurring-subscriptions', [R::class, 'subscriptions']);
Route::put('/commercial/subscriptions/{subscription}/collection-policy', [R::class, 'policy'])->whereNumber('subscription');
Route::post('/commercial/subscription-checkouts/{checkout}/authentication', [R::class, 'authenticate'])->whereNumber('checkout');
Route::get('/commercial/recurring-events', [R::class, 'events']);
