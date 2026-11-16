<?php

use App\Http\Controllers\CommercialFinanceController as C;
use App\Http\Controllers\VendorRevenueCohortController as V;
use Illuminate\Support\Facades\Route;

Route::get('/finance/report', [C::class, 'report']);
Route::get('/commercial/vendors/{vendor}/revenue-cohorts', [V::class, 'index'])->where('vendor', '[0-9]{1,18}');
Route::get('/commercial/vendors/{vendor}/revenue-cohorts/export', [V::class, 'export'])->where('vendor', '[0-9]{1,18}');
Route::get('/finance/refund-requests', [C::class, 'requests']);
Route::post('/finance/invoices/{invoice}/refund-requests', [C::class, 'requestRefund']);
Route::post('/finance/refund-requests/{request}/review', [C::class, 'review'])->where('request', '[0-9]{1,18}');
