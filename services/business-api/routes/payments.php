<?php

use App\Http\Controllers\PaymentCaseEvidenceController;
use App\Http\Controllers\PaymentGatewayController as G;
use Illuminate\Support\Facades\Route;

// Mount inside the existing authenticated /api and /api/v1 groups.
Route::get('/commercial/checkouts', [G::class, 'checkouts']);
Route::post('/commercial/checkouts', [G::class, 'checkout']);
Route::post('/commercial/checkouts/{checkout}/{operation}', [G::class, 'operation'])->where('operation', 'authorize|capture|cancel|refund')->where('checkout', '[0-9]{1,18}');
Route::get('/commercial/cases', [G::class, 'cases']);
Route::post('/commercial/cases', [G::class, 'createCase']);
Route::post('/commercial/cases/{case}/resolve', [G::class, 'resolveCase'])->where('case', '[0-9]{1,18}');
Route::get('/commercial/reconciliation/{invoice}', [G::class, 'reconciliation'])->where('invoice', '[0-9]{1,18}');

require __DIR__.'/payment_recovery.php';

Route::get('/commercial/cases/{case}/evidence', [PaymentCaseEvidenceController::class, 'index'])->where('case', '[0-9]{1,18}');
Route::post('/commercial/cases/{case}/evidence', [PaymentCaseEvidenceController::class, 'upload'])->where('case', '[0-9]{1,18}');
Route::get('/commercial/cases/{case}/evidence/{evidence}', [PaymentCaseEvidenceController::class, 'download'])->where('case', '[0-9]{1,18}')->where('evidence', '[0-9]{1,18}');
