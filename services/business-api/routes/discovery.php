<?php

use App\Http\Controllers\DiscoveryLookupController as D;
use App\Http\Controllers\OpenChargeMapDiscoveryController as O;
use Illuminate\Support\Facades\Route;

Route::get('/discovery/capabilities', [D::class, 'capabilities'])->middleware('throttle:catalog');
Route::get('/discovery/open-charge-map/capabilities', [O::class, 'capabilities'])->middleware('throttle:catalog');
Route::middleware('throttle:discovery-lookups')->group(function () {
    Route::post('/discovery/addresses', [D::class, 'addresses']);
    Route::post('/discovery/route', [D::class, 'route']);
    Route::post('/discovery/open-charge-map/search', [O::class, 'search']);
});
