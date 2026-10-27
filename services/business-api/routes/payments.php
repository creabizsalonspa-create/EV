<?php

use App\Http\Controllers\PaymentGatewayController as G;
use Illuminate\Support\Facades\Route;

// Mount inside the existing authenticated /api and /api/v1 groups.
Route::get('/commercial/checkouts', [G::class, 'checkouts']);
Route::post('/commercial/checkouts', [G::class, 'checkout']);
Route::post('/commercial/checkouts/{checkout}/{operation}', [G::class, 'operation'])->where('operation', 'authorize|capture|cancel|refund');
Route::get('/commercial/cases', [G::class, 'cases']);
Route::post('/commercial/cases', [G::class, 'createCase']);
Route::post('/commercial/cases/{case}/resolve', [G::class, 'resolveCase']);
Route::get('/commercial/reconciliation/{invoice}', [G::class, 'reconciliation']);
