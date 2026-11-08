<?php

use App\Http\Controllers\SubscriptionController as S;
use App\Http\Controllers\SubscriptionDunningController;
use Illuminate\Support\Facades\Route;

// Mount inside the existing authenticated API groups; cookie and token clients share records.
Route::get('/commercial/plans', [S::class, 'plans']);
Route::post('/commercial/plans', [S::class, 'publish']);
Route::patch('/commercial/plans/{plan}/availability', [S::class, 'availability'])->where('plan', '[0-9]{1,18}');
Route::get('/commercial/subscriptions', [S::class, 'index']);
Route::post('/commercial/subscriptions', [S::class, 'subscribe']);
Route::get('/commercial/subscriptions/{subscription}', [S::class, 'show'])->where('subscription', '[0-9]{1,18}');
Route::post('/commercial/subscriptions/{subscription}/change', [S::class, 'change'])->where('subscription', '[0-9]{1,18}');
Route::post('/commercial/subscriptions/{subscription}/mock-payment', [S::class, 'mockPayment'])->where('subscription', '[0-9]{1,18}');
Route::post('/commercial/subscriptions/{subscription}/renew', [S::class, 'renew'])->where('subscription', '[0-9]{1,18}');
Route::get('/commercial/subscriptions/{subscription}/events', [S::class, 'history'])->where('subscription', '[0-9]{1,18}');

Route::get('/commercial/subscription-notices', [SubscriptionDunningController::class, 'index']);
