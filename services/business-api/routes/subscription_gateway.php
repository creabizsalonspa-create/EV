<?php

use App\Http\Controllers\SubscriptionGatewayController as G;
use Illuminate\Support\Facades\Route;

// Mount inside existing authenticated cookie and token API groups.
Route::get('/commercial/subscription-checkouts', [G::class, 'index']);
Route::post('/commercial/subscriptions/{subscription}/checkouts', [G::class, 'checkout'])->whereNumber('subscription');
Route::post('/commercial/subscription-checkouts/{checkout}/{operation}', [G::class, 'operate'])->whereNumber('checkout')->where('operation', 'authorize|capture|cancel');
Route::get('/commercial/subscription-checkouts/{checkout}/reconciliation', [G::class, 'reconciliation'])->whereNumber('checkout');
