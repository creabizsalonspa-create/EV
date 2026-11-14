<?php

use App\Http\Controllers\CommercialCampaignController as C;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/campaign-stations', [C::class, 'stations']);
Route::get('/commercial/campaign-packages', [C::class, 'packages']);
Route::get('/commercial/campaign-placements', [C::class, 'eligible']);
Route::get('/commercial/campaigns', [C::class, 'index']);
Route::post('/commercial/campaigns', [C::class, 'create']);
Route::get('/commercial/campaigns/{campaign}', [C::class, 'show'])->where('campaign', '[0-9]{1,18}');
Route::post('/commercial/campaigns/{campaign}/{action}', [C::class, 'action'])->where('campaign', '[0-9]{1,18}')->whereIn('action', ['submit', 'approve', 'reject', 'pause', 'resume', 'cancel', 'suspend', 'reinstate']);

Route::post('/commercial/campaign-intents/cancel', [C::class, 'cancelIntent']);
