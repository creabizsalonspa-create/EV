<?php

use App\Http\Controllers\CorporateBillingController as C;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/corporate', [C::class, 'index']);
Route::post('/commercial/corporate/fleets/{fleet}/agreements', [C::class, 'publish'])->where('fleet', '[0-9]{1,18}');
Route::post('/commercial/corporate/agreements/{agreement}/accept', [C::class, 'accept'])->where('agreement', '[0-9]{1,18}');
Route::get('/commercial/corporate/agreements/{agreement}/claims', [C::class, 'claims'])->where('agreement', '[0-9]{1,18}');
Route::post('/commercial/corporate/agreements/{agreement}/claims', [C::class, 'claim'])->where('agreement', '[0-9]{1,18}');
Route::post('/commercial/corporate/claims/{claim}/review', [C::class, 'decide'])->where('claim', '[0-9]{1,18}');
Route::get('/commercial/corporate/agreements/{agreement}/statements', [C::class, 'statements'])->where('agreement', '[0-9]{1,18}');
Route::post('/commercial/corporate/agreements/{agreement}/statements', [C::class, 'issue'])->where('agreement', '[0-9]{1,18}');
Route::get('/commercial/corporate/statements/{statement}', [C::class, 'show'])->where('statement', '[0-9]{1,18}');
Route::get('/commercial/corporate/statements/{statement}/export', [C::class, 'csv'])->where('statement', '[0-9]{1,18}');
Route::post('/commercial/corporate/statements/{statement}/simulate-payment', [C::class, 'pay'])->where('statement', '[0-9]{1,18}');
