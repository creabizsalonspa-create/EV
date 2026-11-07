<?php

use App\Http\Controllers\SubscriptionCreditController;
use Illuminate\Support\Facades\Route;

// Mount stateless in routes/api.php, outside user authentication; raw-body signature required.
Route::post('/subscription-credit-payments/stripe-test/webhook', [SubscriptionCreditController::class, 'stripeWebhook'])->middleware('throttle:platform-api');
