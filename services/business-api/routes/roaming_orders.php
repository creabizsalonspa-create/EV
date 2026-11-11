<?php

use App\Http\Controllers\OcpiTestInvoiceController;
use App\Http\Controllers\RoamingOrderController as R;
use Illuminate\Support\Facades\Route;

Route::get('/sandbox-capabilities', [R::class, 'capabilities']);
Route::get('/locations/{location}/sandbox-offers', [R::class, 'offers'])->where('location', '[0-9]{1,18}');
Route::post('/locations/{location}/sandbox-orders', [R::class, 'create'])->where('location', '[0-9]{1,18}');
Route::get('/sandbox-orders', [R::class, 'index']);
Route::get('/sandbox-orders/{order}', [R::class, 'show'])->where('order', '[0-9]{1,18}');
Route::post('/sandbox-orders/{order}/reconcile', [R::class, 'reconcile'])->where('order', '[0-9]{1,18}');
Route::post('/sandbox-orders/{order}/stop', [R::class, 'stop'])->where('order', '[0-9]{1,18}');
Route::delete('/sandbox-orders/{order}/authority', [R::class, 'revoke'])->where('order', '[0-9]{1,18}');

Route::get('/sandbox-orders/{order}/test-invoice-preview', [OcpiTestInvoiceController::class, 'preview'])->where('order', '[0-9]{1,18}');
Route::post('/sandbox-orders/{order}/test-invoice', [OcpiTestInvoiceController::class, 'issue'])->where('order', '[0-9]{1,18}');

Route::post('/sandbox-orders/{order}/test-invoice-resolve', [OcpiTestInvoiceController::class, 'resolve'])->where('order', '[0-9]{1,18}');
