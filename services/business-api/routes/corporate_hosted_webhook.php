<?php

use App\Http\Controllers\CorporateHostedCheckoutController;
use Illuminate\Support\Facades\Route;

Route::post('/corporate-payments/stripe-test/webhook', [CorporateHostedCheckoutController::class, 'webhook'])->middleware('throttle:platform-api');
