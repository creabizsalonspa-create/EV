<?php

use App\Http\Controllers\ExternalDisputesController as D;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/external-disputes', [D::class, 'index']);
Route::get('/commercial/external-disputes/{dispute}', [D::class, 'show'])->whereNumber('dispute');
Route::post('/commercial/external-disputes/{dispute}/reconcile', [D::class, 'reconcile'])->whereNumber('dispute');
Route::post('/commercial/external-disputes/{dispute}/evidence', [D::class, 'evidence'])->whereNumber('dispute');

Route::post('/commercial/external-disputes/{dispute}/evidence/{operation}/retry', [D::class, 'retry'])->whereNumber('dispute')->whereNumber('operation');
