<?php

use App\Http\Controllers\PayoutWebhookController;
use Illuminate\Support\Facades\Route;

// Stateless public route protected by Stripe timestamp/HMAC and scoped TEST evidence.
Route::post('/webhooks/payouts/stripe-test', [PayoutWebhookController::class, 'stripe'])->middleware('throttle:60,1');
