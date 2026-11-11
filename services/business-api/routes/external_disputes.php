<?php

use App\Http\Controllers\ExternalDisputesController as D;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/external-disputes', [D::class, 'index']);
Route::get('/commercial/external-disputes/{dispute}', [D::class, 'show'])->where('dispute', '[0-9]{1,18}');
Route::post('/commercial/external-disputes/{dispute}/reconcile', [D::class, 'reconcile'])->where('dispute', '[0-9]{1,18}');
Route::post('/commercial/external-disputes/{dispute}/evidence', [D::class, 'evidence'])->where('dispute', '[0-9]{1,18}');

Route::post('/commercial/external-disputes/{dispute}/evidence/{operation}/retry', [D::class, 'retry'])->where('dispute', '[0-9]{1,18}')->where('operation', '[0-9]{1,18}');
