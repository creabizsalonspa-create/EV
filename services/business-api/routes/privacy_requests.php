<?php

use App\Http\Controllers\PrivacyRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/privacy-requests', [PrivacyRequestController::class, 'index']);
Route::post('/privacy-requests', [PrivacyRequestController::class, 'create']);
Route::post('/privacy-requests/{privacyRequest}/withdraw', [PrivacyRequestController::class, 'withdraw'])->whereNumber('privacyRequest');
Route::get('/admin/privacy-requests', [PrivacyRequestController::class, 'adminIndex']);
Route::patch('/admin/privacy-requests/{privacyRequest}', [PrivacyRequestController::class, 'review'])->whereNumber('privacyRequest');
