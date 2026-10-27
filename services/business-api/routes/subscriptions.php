<?php

use App\Http\Controllers\SubscriptionController as S;
use Illuminate\Support\Facades\Route;

// Mount inside the existing authenticated API groups; cookie and token clients share records.
Route::get('/commercial/plans', [S::class, 'plans']);
Route::post('/commercial/plans', [S::class, 'publish']);
Route::patch('/commercial/plans/{plan}/availability', [S::class, 'availability'])->whereNumber('plan');
Route::get('/commercial/subscriptions', [S::class, 'index']);
Route::post('/commercial/subscriptions', [S::class, 'subscribe']);
Route::get('/commercial/subscriptions/{subscription}', [S::class, 'show'])->whereNumber('subscription');
Route::post('/commercial/subscriptions/{subscription}/change', [S::class, 'change'])->whereNumber('subscription');
Route::post('/commercial/subscriptions/{subscription}/mock-payment', [S::class, 'mockPayment'])->whereNumber('subscription');
Route::post('/commercial/subscriptions/{subscription}/renew', [S::class, 'renew'])->whereNumber('subscription');
Route::get('/commercial/subscriptions/{subscription}/events', [S::class, 'history'])->whereNumber('subscription');
