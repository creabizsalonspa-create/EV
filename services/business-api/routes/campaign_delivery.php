<?php

use App\Http\Controllers\CommercialCampaignController;
use Illuminate\Support\Facades\Route;

Route::post('/internal/commercial/campaign-deliveries', [CommercialCampaignController::class, 'delivery'])->middleware('throttle:platform-api');
