<?php

use App\Http\Controllers\OcpiTechnicalAgreementController;
use Illuminate\Support\Facades\Route;

Route::get('/ocpi/providers/{provider}/technical-agreements', [OcpiTechnicalAgreementController::class, 'show'])->where('provider', '[0-9]{1,18}');
Route::post('/ocpi/providers/{provider}/technical-agreements', [OcpiTechnicalAgreementController::class, 'propose'])->where('provider', '[0-9]{1,18}');
Route::post('/ocpi/providers/{provider}/technical-agreements/{agreement}/decision', [OcpiTechnicalAgreementController::class, 'decide'])->where('provider', '[0-9]{1,18}')->where('agreement', '[0-9]{1,18}');
