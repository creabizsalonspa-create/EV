<?php

use App\Http\Controllers\SubscriptionCreditController as C;
use Illuminate\Support\Facades\Route;

// Mount inside existing authenticated cookie and token groups.
Route::get('/commercial/subscriptions/{subscription}/credits', [C::class, 'index'])->whereNumber('subscription');
Route::post('/commercial/subscriptions/{subscription}/credits', [C::class, 'refund'])->whereNumber('subscription');
Route::get('/commercial/subscription-credits/{credit}', [C::class, 'show'])->whereNumber('credit');
