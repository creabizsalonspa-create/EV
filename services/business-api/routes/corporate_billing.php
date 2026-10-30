<?php

use App\Http\Controllers\CorporateBillingController as C;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/corporate', [C::class, 'index']);
Route::post('/commercial/corporate/fleets/{fleet}/agreements', [C::class, 'publish'])->whereNumber('fleet');
Route::post('/commercial/corporate/agreements/{agreement}/accept', [C::class, 'accept'])->whereNumber('agreement');
Route::get('/commercial/corporate/agreements/{agreement}/claims', [C::class, 'claims'])->whereNumber('agreement');
Route::post('/commercial/corporate/agreements/{agreement}/claims', [C::class, 'claim'])->whereNumber('agreement');
Route::post('/commercial/corporate/claims/{claim}/review', [C::class, 'decide'])->whereNumber('claim');
Route::get('/commercial/corporate/agreements/{agreement}/statements', [C::class, 'statements'])->whereNumber('agreement');
Route::post('/commercial/corporate/agreements/{agreement}/statements', [C::class, 'issue'])->whereNumber('agreement');
Route::get('/commercial/corporate/statements/{statement}', [C::class, 'show'])->whereNumber('statement');
Route::get('/commercial/corporate/statements/{statement}/export', [C::class, 'csv'])->whereNumber('statement');
Route::post('/commercial/corporate/statements/{statement}/simulate-payment', [C::class, 'pay'])->whereNumber('statement');
