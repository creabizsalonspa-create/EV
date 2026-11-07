<?php

use App\Http\Controllers\OcpiTestInvoiceController;
use App\Http\Controllers\RoamingOrderController as R;
use Illuminate\Support\Facades\Route;

Route::get('/sandbox-capabilities', [R::class, 'capabilities']);
Route::get('/locations/{location}/sandbox-offers', [R::class, 'offers'])->whereNumber('location');
Route::post('/locations/{location}/sandbox-orders', [R::class, 'create'])->whereNumber('location');
Route::get('/sandbox-orders', [R::class, 'index']);
Route::get('/sandbox-orders/{order}', [R::class, 'show'])->whereNumber('order');
Route::post('/sandbox-orders/{order}/reconcile', [R::class, 'reconcile'])->whereNumber('order');
Route::post('/sandbox-orders/{order}/stop', [R::class, 'stop'])->whereNumber('order');
Route::delete('/sandbox-orders/{order}/authority', [R::class, 'revoke'])->whereNumber('order');

Route::get('/sandbox-orders/{order}/test-invoice-preview', [OcpiTestInvoiceController::class, 'preview'])->whereNumber('order');
Route::post('/sandbox-orders/{order}/test-invoice', [OcpiTestInvoiceController::class, 'issue'])->whereNumber('order');

Route::post('/sandbox-orders/{order}/test-invoice-resolve', [OcpiTestInvoiceController::class, 'resolve'])->whereNumber('order');
