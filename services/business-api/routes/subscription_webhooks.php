<?php

use App\Http\Controllers\SubscriptionGatewayController;
use Illuminate\Support\Facades\Route;

// Mount stateless via routes/api.php, outside user auth groups. Raw-body signature is mandatory.
Route::post('/subscription-payments/stripe-test/webhook', [SubscriptionGatewayController::class, 'stripeWebhook'])->middleware('throttle:platform-api');
