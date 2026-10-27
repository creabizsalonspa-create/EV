<?php

use App\Http\Controllers\FinanceController as F;
use Illuminate\Support\Facades\Route;

// Load under the parent application's authenticated/throttled api or api/v1 prefix.
Route::get('/stations/{station}/tariffs', [F::class, 'tariffs']);
Route::post('/stations/{station}/tariffs', [F::class, 'createTariff']);
Route::get('/finance/invoices', [F::class, 'invoices']);
Route::post('/finance/sessions/{session}/invoice', [F::class, 'issue']);
Route::get('/finance/invoices/{invoice}', [F::class, 'show']);
Route::post('/finance/invoices/{invoice}/simulate-payment', [F::class, 'payment']);
Route::post('/finance/invoices/{invoice}/simulate-refund', [F::class, 'refund']);
Route::get('/finance/invoices/{invoice}/journal', [F::class, 'journal']);
