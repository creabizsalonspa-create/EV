<?php

use App\Http\Controllers\SubscriptionGatewayController as G;
use Illuminate\Support\Facades\Route;

// Mount inside existing authenticated cookie and token API groups.
Route::get('/commercial/subscription-checkouts', [G::class, 'index']);
Route::post('/commercial/subscriptions/{subscription}/checkouts', [G::class, 'checkout'])->where('subscription', '[0-9]{1,18}');
Route::post('/commercial/subscription-checkouts/{checkout}/{operation}', [G::class, 'operate'])->where('checkout', '[0-9]{1,18}')->where('operation', 'authorize|capture|cancel');
Route::get('/commercial/subscription-checkouts/{checkout}/reconciliation', [G::class, 'reconciliation'])->where('checkout', '[0-9]{1,18}');
