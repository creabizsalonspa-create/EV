<?php

use App\Http\Controllers\SubscriptionCreditController as C;
use Illuminate\Support\Facades\Route;

// Mount inside existing authenticated cookie and token groups.
Route::get('/commercial/subscriptions/{subscription}/credits', [C::class, 'index'])->where('subscription', '[0-9]{1,18}');
Route::post('/commercial/subscriptions/{subscription}/credits', [C::class, 'refund'])->where('subscription', '[0-9]{1,18}');
Route::get('/commercial/subscription-credits/{credit}', [C::class, 'show'])->where('credit', '[0-9]{1,18}');
