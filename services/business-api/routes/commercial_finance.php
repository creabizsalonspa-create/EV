<?php

use App\Http\Controllers\CommercialFinanceController as C;
use Illuminate\Support\Facades\Route;

Route::get('/finance/report', [C::class, 'report']);
Route::get('/finance/refund-requests', [C::class, 'requests']);
Route::post('/finance/invoices/{invoice}/refund-requests', [C::class, 'requestRefund']);
Route::post('/finance/refund-requests/{request}/review', [C::class, 'review']);
