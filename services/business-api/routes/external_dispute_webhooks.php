<?php

use App\Http\Controllers\ExternalDisputesController;
use Illuminate\Support\Facades\Route;

Route::post('/disputes/stripe-test/webhook', [ExternalDisputesController::class, 'webhook'])->middleware('throttle:platform-api');
