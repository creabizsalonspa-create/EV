<?php

use App\Http\Controllers\CorporateHostedCheckoutController as H;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/corporate/hosted-checkouts', [H::class, 'index']);
Route::post('/commercial/corporate/hosted-checkouts', [H::class, 'create']);
Route::get('/commercial/corporate/hosted-checkouts/by-reference/{reference}', [H::class, 'reference'])->whereUuid('reference');
Route::get('/commercial/corporate/hosted-checkouts/{hosted}', [H::class, 'show'])->where('hosted', '[0-9]{1,18}');
Route::get('/commercial/corporate/hosted-checkouts/{hosted}/events', [H::class, 'events'])->where('hosted', '[0-9]{1,18}');
foreach (['inspect', 'capture', 'cancel', 'simulate'] as $action) {
    Route::post('/commercial/corporate/hosted-checkouts/{hosted}/'.$action, [H::class, $action])->where('hosted', '[0-9]{1,18}');
}
Route::post('/commercial/corporate/hosted-checkouts/{hosted}/retry-preparation', [H::class, 'retryPreparation'])->where('hosted', '[0-9]{1,18}');
