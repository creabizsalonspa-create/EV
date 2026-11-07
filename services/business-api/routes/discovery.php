<?php

use App\Http\Controllers\DiscoveryLookupController as D;
use Illuminate\Support\Facades\Route;

Route::get('/discovery/capabilities', [D::class, 'capabilities'])->middleware('throttle:catalog');
Route::middleware('throttle:discovery-lookups')->group(function () {
    Route::post('/discovery/addresses', [D::class, 'addresses']);
    Route::post('/discovery/route', [D::class, 'route']);
});
