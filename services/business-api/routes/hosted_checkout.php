<?php

use App\Http\Controllers\HostedCheckoutController as H;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/hosted-checkouts', [H::class, 'index']);
Route::post('/commercial/hosted-checkouts', [H::class, 'create']);
Route::get('/commercial/hosted-checkouts/by-reference/{reference}', [H::class, 'reference'])->whereUuid('reference');
Route::get('/commercial/hosted-checkouts/{hosted}', [H::class, 'show'])->whereNumber('hosted');
Route::get('/commercial/hosted-checkouts/{hosted}/events', [H::class, 'events'])->whereNumber('hosted');
foreach (['inspect', 'capture', 'cancel', 'simulate'] as $action) {
    Route::post('/commercial/hosted-checkouts/{hosted}/'.$action, [H::class, $action])->whereNumber('hosted');
}

Route::post('/commercial/hosted-checkouts/{hosted}/retry-preparation', [H::class, 'retryPreparation'])->whereNumber('hosted');
